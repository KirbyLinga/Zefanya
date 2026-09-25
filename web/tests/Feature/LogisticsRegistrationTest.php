<?php

namespace Tests\Feature;

use App\Enums\LogisticsProviderStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Notifications\Logistics\LogisticsRegistrationOtp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end coverage for the logistics registration modal's backend:
 * route fix, store + validation, cross-role email rejection, upload rules,
 * OTP verification, and admin approval authorization.
 */
class LogisticsRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────

    private function fakePdf(string $name = 'document.pdf', int $kilobytes = 100): UploadedFile
    {
        Storage::fake('local');

        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    private function logisticsPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Liza',
            'last_name' => 'Ramos',
            'middle_initial' => 'B',
            'sex' => 'female',
            'email' => 'liza'.uniqid().'@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'contact_no' => '09171234567',
            'birthday' => '1995-06-20',
            'business_name' => 'Ramos Logistics '.uniqid(),
            'address_mode' => 'api',
            'province' => '0300000000',
            'province_name' => 'Batangas',
            'municipality' => '0306800000',
            'municipality_name' => 'Lipa City',
            'barangay' => '030680001',
            'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal St., Purok 2',
            'house_number' => '123',
            'address_detail' => 'Near the plaza',
            'upload_id' => $this->fakePdf('valid-id.pdf'),
            'dti_permit_upload' => $this->fakePdf('dti-permit.pdf'),
        ], $overrides);
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    // ── Route fix ─────────────────────────────────────────────────────────

    public function test_get_register_logistics_redirects_to_the_register_type_modal(): void
    {
        $this->get(route('register.logistics'))
            ->assertRedirect(route('register.type', ['open' => 'logistics']));
    }

    public function test_register_type_page_renders_the_logistics_modal_and_trigger(): void
    {
        $this->get(route('register.type'))
            ->assertOk()
            ->assertSee('data-logistics-register-trigger', false)
            ->assertSee('logisticsRegisterForm', false);
    }

    public function test_logistics_pending_page_renders(): void
    {
        $this->get(route('register.logistics.pending'))
            ->assertOk()
            ->assertSee('Email verified');
    }

    public function test_otp_verification_page_renders_for_an_unverified_provider(): void
    {
        $provider = LogisticsProvider::factory()->pendingVerification()->create();

        $this->get(route('register.logistics.verify-otp', $provider))
            ->assertOk()
            ->assertSee('Verify your email')
            ->assertSee($provider->email);
    }

    // ── Store: happy path ─────────────────────────────────────────────────

    public function test_logistics_registration_succeeds_in_api_mode(): void
    {
        $response = $this->postJson(route('register.logistics.store'), $this->logisticsPayload());

        $response->assertStatus(201)
            ->assertJsonStructure(['logistics_provider_id', 'email', 'verify_url']);

        $provider = LogisticsProvider::query()->latest('id')->firstOrFail();

        $this->assertSame(LogisticsProviderStatus::PendingVerification, $provider->status);
        $this->assertSame(Carbon::parse('1995-06-20')->age, $provider->age);
        $this->assertSame('123', $provider->house_number);
        $this->assertSame('Near the plaza', $provider->address_detail);
        $this->assertStringStartsWith('logistics-ids/', $provider->upload_id_path);
        $this->assertStringStartsWith('logistics-permits/', $provider->dti_permit_path);
    }

    public function test_logistics_registration_succeeds_in_manual_mode(): void
    {
        $response = $this->postJson(route('register.logistics.store'), $this->logisticsPayload([
            'address_mode' => 'manual',
            'province' => null,
            'province_name' => 'Batangas',
            'municipality' => null,
            'municipality_name' => 'Lipa City',
            'barangay' => null,
            'barangay_name' => 'Barangay 1',
            'house_number' => null,
            'address_detail' => null,
        ]));

        $response->assertStatus(201);

        $this->assertDatabaseHas('logistics_providers', [
            'address_mode' => 'manual',
            'house_number' => null,
            'address_detail' => null,
        ]);
    }

    public function test_logistics_registration_stores_the_submitted_street(): void
    {
        $street = '7 Kalayaan St., Brgy. Uno';

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['street' => $street]))
            ->assertStatus(201);

        $this->assertDatabaseHas('logistics_providers', ['street' => $street]);
    }

    // ── Store: validation ─────────────────────────────────────────────────

    public function test_logistics_registration_fails_without_street_in_api_mode(): void
    {
        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['street' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('street');
    }

    public function test_logistics_registration_fails_without_street_in_manual_mode(): void
    {
        $this->postJson(route('register.logistics.store'), $this->logisticsPayload([
            'address_mode' => 'manual',
            'province' => null,
            'municipality' => null,
            'barangay' => null,
            'street' => '',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('street');
    }

    // ── Email uniqueness across roles ─────────────────────────────────────

    public function test_logistics_registration_rejects_an_email_used_by_a_buyer(): void
    {
        $buyer = Buyer::factory()->create(['email' => 'shared'.uniqid().'@example.com']);

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['email' => $buyer->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_logistics_registration_rejects_an_email_used_by_a_seller(): void
    {
        $seller = Seller::factory()->create(['email' => 'shared'.uniqid().'@example.com']);

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['email' => $seller->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    public function test_logistics_registration_rejects_a_duplicate_logistics_email(): void
    {
        $existing = LogisticsProvider::factory()->create();

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['email' => $existing->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    // ── Uploads ───────────────────────────────────────────────────────────

    public function test_upload_id_must_be_an_image_or_pdf(): void
    {
        Storage::fake('local');

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload([
            'upload_id' => UploadedFile::fake()->create('id.txt', 10, 'text/plain'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('upload_id');
    }

    public function test_dti_permit_must_be_smaller_than_five_megabytes(): void
    {
        $this->postJson(route('register.logistics.store'), $this->logisticsPayload([
            'dti_permit_upload' => $this->fakePdf('big-permit.pdf', 6000),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('dti_permit_upload');
    }

    public function test_dti_permit_is_required(): void
    {
        Storage::fake('local');

        $payload = $this->logisticsPayload();
        unset($payload['dti_permit_upload']);

        $this->postJson(route('register.logistics.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('dti_permit_upload');
    }

    // ── OTP ───────────────────────────────────────────────────────────────

    public function test_otp_verification_moves_the_provider_to_pending_approval(): void
    {
        Notification::fake();

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload())->assertStatus(201);

        $provider = LogisticsProvider::query()->latest('id')->firstOrFail();

        $otp = null;
        Notification::assertSentTo($provider, LogisticsRegistrationOtp::class, function ($notification) use (&$otp): bool {
            $otp = $notification->otp;

            return true;
        });
        $this->assertNotNull($otp, 'No OTP notification was sent.');

        $this->postJson(route('register.logistics.verify-otp.store', $provider), ['otp' => $otp])
            ->assertOk()
            ->assertJsonPath('redirect', route('register.logistics.pending'));

        $provider->refresh();

        $this->assertSame(LogisticsProviderStatus::PendingApproval, $provider->status);
        $this->assertNotNull($provider->email_verified_at);
        $this->assertNull($provider->email_verification_code);
    }

    public function test_an_incorrect_otp_is_rejected_and_leaves_the_status_unchanged(): void
    {
        Notification::fake();

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload())->assertStatus(201);

        $provider = LogisticsProvider::query()->latest('id')->firstOrFail();

        $this->postJson(route('register.logistics.verify-otp.store', $provider), ['otp' => '000000'])
            ->assertStatus(422);

        $this->assertSame(LogisticsProviderStatus::PendingVerification, $provider->fresh()->status);
    }

    // ── Admin approval ────────────────────────────────────────────────────

    public function test_unauthenticated_visitor_cannot_approve_a_logistics_registration(): void
    {
        $provider = LogisticsProvider::factory()->pendingApproval()->create();

        $this->post(route('admin.registrations.logistics.approve', $provider))
            ->assertRedirect(route('admin.login'));

        $this->assertSame(LogisticsProviderStatus::PendingApproval, $provider->fresh()->status);
    }

    public function test_a_signed_in_buyer_cannot_approve_a_logistics_registration(): void
    {
        $provider = LogisticsProvider::factory()->pendingApproval()->create();
        $buyer = Buyer::factory()->create();

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.registrations.logistics.approve', $provider))
            ->assertRedirect(route('admin.login'));

        $this->assertSame(LogisticsProviderStatus::PendingApproval, $provider->fresh()->status);
    }

    public function test_admin_can_approve_a_logistics_registration(): void
    {
        Notification::fake();

        $provider = LogisticsProvider::factory()->pendingApproval()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.registrations.logistics.approve', $provider))
            ->assertRedirect();

        $provider->refresh();

        $this->assertSame(LogisticsProviderStatus::Approved, $provider->status);
        $this->assertNotNull($provider->approved_at);
        $this->assertNotNull($provider->approved_by);
    }

    public function test_admin_can_reject_a_logistics_registration_with_a_reason(): void
    {
        Notification::fake();

        $provider = LogisticsProvider::factory()->pendingApproval()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.registrations.logistics.reject', $provider), [
                'rejection_reason' => 'DTI permit is unreadable.',
            ])
            ->assertRedirect();

        $provider->refresh();

        $this->assertSame(LogisticsProviderStatus::Rejected, $provider->status);
        $this->assertSame('DTI permit is unreadable.', $provider->rejection_reason);
    }

    public function test_admin_registrations_view_lists_a_pending_logistics_application(): void
    {
        $provider = LogisticsProvider::factory()->pendingApproval()->create([
            'business_name' => 'Ramos Cargo Movers',
            'street' => '88 Rizal Blvd.',
            'house_number' => '88',
            'municipality_name' => 'Batangas City',
            'province_name' => 'Batangas',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.registrations.index', ['view' => $provider->id, 'type' => 'logistics']))
            ->assertOk()
            ->assertSee('Ramos Cargo Movers')
            ->assertSee('88 Rizal Blvd.')
            ->assertSee('View DTI permit');
    }
}
