<?php

namespace Tests\Feature\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Notifications\Logistics\LogisticsRegistrationOtp;
use App\Support\EmailMask;
use App\Support\SignedOtpLink;
use Carbon\Carbon;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'tin' => '123-456-789-000',
            'sec_number' => 'AB123456789',
            'service_areas' => ['030680000'],
            'address_mode' => 'api',
            'region_code' => '030000000',
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
            'terms' => '1',
        ], $overrides);
    }

    public function test_log_025_tin_format_is_required(): void
    {
        foreach (['', '123456789000', '123-456-789', 'ABC-456-789-000'] as $tin) {
            $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['tin' => $tin]))
                ->assertUnprocessable()->assertJsonValidationErrors(['tin']);
        }
    }

    public function test_log_026_duplicate_tin_is_rejected(): void
    {
        LogisticsProvider::factory()->create(['tin' => '123-456-789-000']);
        $this->postJson(route('register.logistics.store'), $this->logisticsPayload())
            ->assertUnprocessable()->assertJsonPath('errors.tin.0', 'This TIN is already registered.');
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
        $provider = LogisticsProvider::factory()->unverified()->create();

        $this->get(SignedOtpLink::showUrl('logistics', $provider))
            ->assertOk()
            ->assertSee('Verify your email')
            ->assertSee(EmailMask::mask($provider->email));
    }

    // ── Store: happy path ─────────────────────────────────────────────────

    public function test_logistics_registration_succeeds_in_api_mode(): void
    {
        $response = $this->postJson(route('register.logistics.store'), $this->logisticsPayload());

        $response->assertStatus(201)
            ->assertJsonStructure(['logistics_provider_id', 'email', 'verify_url']);

        $provider = LogisticsProvider::query()->latest('id')->firstOrFail();

        $this->assertSame(LogisticsProviderStatus::Unverified, $provider->status);
        $this->assertSame(Carbon::parse('1995-06-20')->age, $provider->age);
        $this->assertSame('123', $provider->house_number);
        $this->assertSame('Near the plaza', $provider->address_detail);
        $this->assertStringStartsWith('logistics-ids/', $provider->upload_id_path);
        $this->assertStringStartsWith('logistics-permits/', $provider->dti_permit_path);
    }

    public function test_logistics_registration_rejects_manual_address_mode(): void
    {
        $this->postJson(route('register.logistics.store'), $this->logisticsPayload([
            'address_mode' => 'manual',
            'province' => null,
            'province_name' => 'Batangas',
            'municipality' => null,
            'municipality_name' => 'Lipa City',
            'barangay' => null,
            'barangay_name' => 'Barangay 1',
            'house_number' => null,
            'address_detail' => null,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('address_mode');
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

    public function test_logistics_registration_rejects_an_email_used_by_a_rider(): void
    {
        $rider = Rider::factory()->create(['email' => 'rider'.uniqid().'@example.com']);

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['email' => strtoupper($rider->email)]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    /** @return array<string, array{string}> */
    public static function invalidEmails(): array
    {
        return [
            'no @' => ['operations.bilishatidexample.com'],
            'no domain' => ['operations.bilishatid@'],
            'no name' => ['@example.com'],
            'no .com' => ['operations.bilishatid@example'],
            'space' => ['operations bilishatid@example.com'],
        ];
    }

    #[DataProvider('invalidEmails')]
    public function test_log_007_invalid_email_formats_are_rejected(string $email): void
    {
        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['email' => $email]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'Enter a valid email address']);

        $this->assertDatabaseCount('logistics_providers', 0);
    }

    public function test_log_009_registered_email_in_capitals_is_a_duplicate(): void
    {
        $existing = LogisticsProvider::factory()->create(['email' => 'operations'.uniqid().'@example.com']);

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['email' => strtoupper($existing->email)]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    /** @return array<string, array{string, string}> */
    public static function invalidMobiles(): array
    {
        return [
            'letters' => ['0917ABC4567', 'Enter a valid mobile number'],
            'symbols, too short' => ['0917-123-456', 'Enter a valid mobile number'],
            'not starting with 09' => ['12345678901', 'Enter a valid mobile number'],
            'landline' => ['02-8123-4567', 'Enter a valid mobile number'],
            '10 digits' => ['0917123456', 'Mobile number must be 11 digits'],
            '12 digits' => ['091712345678', 'Mobile number must be 11 digits'],
        ];
    }

    #[DataProvider('invalidMobiles')]
    public function test_log_011_012_invalid_mobiles_are_rejected(string $mobile, string $message): void
    {
        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['contact_no' => $mobile]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_no' => $message]);

        $this->assertDatabaseCount('logistics_providers', 0);
    }

    public function test_log_012_eleven_digit_mobile_is_accepted(): void
    {
        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['contact_no' => '09170000001']))
            ->assertStatus(201);

        $this->assertDatabaseHas('logistics_providers', ['contact_no' => '09170000001']);
    }

    public function test_log_013_registered_mobile_is_rejected(): void
    {
        LogisticsProvider::factory()->create(['contact_no' => '09285551234']);

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['contact_no' => '09285551234']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_no' => 'This mobile number is already in use.']);
    }

    public function test_log_014_registered_mobile_in_plus_63_format_is_rejected(): void
    {
        LogisticsProvider::factory()->create(['contact_no' => '09285551234']);

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['contact_no' => '+639285551234']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_no' => 'This mobile number is already in use.']);
    }

    public function test_plus_63_mobile_is_saved_as_09(): void
    {
        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['contact_no' => '+63 928 555 1234']))
            ->assertStatus(201);

        $this->assertDatabaseHas('logistics_providers', ['contact_no' => '09285551234']);
    }

    public function test_mobile_owned_by_another_role_is_rejected(): void
    {
        Rider::factory()->create(['contact_no' => '09451239876']);

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['contact_no' => '+639451239876']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_no' => 'This mobile number is already in use.']);
    }

    public function test_log_019_registration_blocked_without_accepting_terms(): void
    {
        Notification::fake();

        foreach ([['terms' => null], ['terms' => '0']] as $override) {
            $this->postJson(route('register.logistics.store'), $this->logisticsPayload($override))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['terms' => 'You must accept the Terms and Privacy Policy.']);
        }

        $this->assertDatabaseCount('logistics_providers', 0);
        Notification::assertNothingSent();
    }

    public function test_log_019_modal_renders_the_terms_checkbox(): void
    {
        $this->get(route('register.type'))
            ->assertOk()
            ->assertSee('id="logisticsTerms"', false);
    }

    public function test_log_020_repeat_submission_returns_the_first_registration_with_one_otp(): void
    {
        Notification::fake();
        $payload = $this->logisticsPayload();

        $first = $this->postJson(route('register.logistics.store'), $payload)->assertStatus(201);
        $second = $this->postJson(route('register.logistics.store'), $payload)->assertStatus(201);

        $this->assertSame($first->json('logistics_provider_id'), $second->json('logistics_provider_id'));
        $provider = LogisticsProvider::where('email', $payload['email'])->sole();
        $this->assertSame(1, Notification::sent($provider, LogisticsRegistrationOtp::class)->count());
    }

    public function test_logistics_contact_must_be_18_and_not_born_in_the_future(): void
    {
        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['birthday' => now('Asia/Manila')->subYears(18)->addDay()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['birthday' => 'You must be at least 18 years old.']);

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['birthday' => now('Asia/Manila')->addDay()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['birthday' => 'Please enter a valid date of birth. It cannot be in the future.']);
    }

    public function test_log_010_email_is_trimmed_and_lowercased_before_saving(): void
    {
        $this->postJson(route('register.logistics.store'), $this->logisticsPayload(['email' => '  Logistics.Test01@Example.com  ']))
            ->assertStatus(201);

        $this->assertDatabaseHas('logistics_providers', ['email' => 'logistics.test01@example.com']);
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

    public function test_otp_verification_moves_the_provider_to_pending(): void
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

        $this->postJson(SignedOtpLink::verifyUrl('logistics', $provider), ['otp' => $otp])
            ->assertOk()
            ->assertJsonPath('redirect', route('register.logistics.pending'));

        $provider->refresh();

        $this->assertSame(LogisticsProviderStatus::Pending, $provider->status);
        $this->assertNotNull($provider->email_verified_at);
        $this->assertNull($provider->email_verification_code);
    }

    public function test_an_incorrect_otp_is_rejected_and_leaves_the_status_unchanged(): void
    {
        Notification::fake();

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload())->assertStatus(201);

        $provider = LogisticsProvider::query()->latest('id')->firstOrFail();

        $this->postJson(SignedOtpLink::verifyUrl('logistics', $provider), ['otp' => '000000'])
            ->assertStatus(422);

        $this->assertSame(LogisticsProviderStatus::Unverified, $provider->fresh()->status);
    }

    public function test_log_033_034_035_otp_messages(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-10-09 12:00:00');

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload())->assertStatus(201);
        $provider = LogisticsProvider::query()->latest('id')->firstOrFail();
        $otp = null;
        Notification::assertSentTo($provider, LogisticsRegistrationOtp::class, function ($notification) use (&$otp): bool {
            $otp = $notification->otp;

            return true;
        });
        $url = SignedOtpLink::verifyUrl('logistics', $provider);

        // TC-LOG-034: wrong format.
        foreach (['48291', '4829137', '48A91B', ''] as $bad) {
            $this->postJson($url, ['otp' => $bad])->assertStatus(422)
                ->assertJsonPath('message', 'Enter the 6-digit code from your email.');
        }

        // TC-LOG-033: wrong code.
        $wrong = $otp === '000000' ? '111111' : '000000';
        $this->postJson($url, ['otp' => $wrong])->assertStatus(422)
            ->assertJsonPath('message', "That code doesn't match. Please try again.");

        // TC-LOG-035: the correct code at 5:01 is expired and points at Resend.
        Carbon::setTestNow('2026-10-09 12:05:01');
        $this->postJson($url, ['otp' => $otp])->assertStatus(422)
            ->assertJsonPath('expired', true)
            ->assertJsonPath('message', 'This code has expired. Tap "Resend code" for a new one.');

        $this->assertSame(LogisticsProviderStatus::Unverified, $provider->fresh()->status);
    }

    // ── Admin approval ────────────────────────────────────────────────────

    public function test_unauthenticated_visitor_cannot_approve_a_logistics_registration(): void
    {
        $provider = LogisticsProvider::factory()->pending()->create();

        $this->post(route('admin.registrations.logistics.approve', $provider))
            ->assertRedirect(route('admin.login'));

        $this->assertSame(LogisticsProviderStatus::Pending, $provider->fresh()->status);
    }

    public function test_a_signed_in_buyer_cannot_approve_a_logistics_registration(): void
    {
        $provider = LogisticsProvider::factory()->pending()->create();
        $buyer = Buyer::factory()->create();

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.registrations.logistics.approve', $provider))
            ->assertRedirect(route('admin.login'));

        $this->assertSame(LogisticsProviderStatus::Pending, $provider->fresh()->status);
    }

    public function test_admin_can_approve_a_logistics_registration(): void
    {
        Notification::fake();

        $provider = LogisticsProvider::factory()->pending()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.registrations.logistics.approve', $provider))
            ->assertRedirect();

        $provider->refresh();

        $this->assertSame(LogisticsProviderStatus::Approved, $provider->status);
        $this->assertNotNull($provider->approved_at);
        $this->assertNotNull($provider->approved_by);
        $this->assertNotNull($provider->province_verified_at);
        $this->assertNotNull($provider->province_code);
    }

    public function test_admin_can_reject_a_logistics_registration_with_a_reason(): void
    {
        Notification::fake();

        $provider = LogisticsProvider::factory()->pending()->create();

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
        $provider = LogisticsProvider::factory()->pending()->create([
            'first_name' => 'Ramos',
            'last_name' => 'Cargo',
            'business_name' => 'Ramos Cargo Movers',
            'email' => 'ramos.'.uniqid().'@example.com',
            'street' => '88 Rizal Blvd.',
            'house_number' => '88',
            'municipality_name' => 'Batangas City',
            'province_name' => 'Batangas',
        ]);

        // The reworked registration queue lists applicants by name + email with
        // an account-type badge; the old ?view= detail panel no longer exists.
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.registrations.index', ['type' => 'logistics']))
            ->assertOk()
            ->assertSee('Ramos Cargo')
            ->assertSee($provider->email)
            ->assertSee('Logistics');
    }

    // ── Registration resilience: failed OTP delivery ───────────────────────

    /**
     * Install a controllable notification dispatcher so a test can simulate a
     * mail outage while still inspecting the codes that were "delivered".
     */
    private function fakeNotifier(): object
    {
        $notifier = new class implements Dispatcher
        {
            public bool $fail = false;

            /** @var array<int, object> */
            public array $sent = [];

            public function send($notifiables, $notification)
            {
                if ($this->fail) {
                    throw new \RuntimeException('SMTP connection refused');
                }

                $this->sent[] = $notification;
            }

            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                if ($this->fail) {
                    throw new \RuntimeException('SMTP connection refused');
                }

                $this->sent[] = $notification;
            }

            public function lastCode(): ?string
            {
                $sent = $this->sent;
                $last = end($sent);

                return $last ? $last->otp : null;
            }
        };

        $this->app->instance(Dispatcher::class, $notifier);

        return $notifier;
    }

    public function test_logistics_failed_otp_email_releases_the_email_for_a_clean_retry(): void
    {
        $notifier = $this->fakeNotifier();
        $notifier->fail = true;

        $payload = $this->logisticsPayload();

        $this->postJson(route('register.logistics.store'), $payload)->assertStatus(503);

        // The half-finished attempt must not keep the email/mobile reserved.
        $this->assertDatabaseMissing('logistics_providers', ['email' => $payload['email']]);

        // A plain retry (mail back up) now succeeds with the same details.
        $notifier->fail = false;

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload([
            'email' => $payload['email'],
            'contact_no' => $payload['contact_no'],
        ]))->assertStatus(201);

        $provider = LogisticsProvider::where('email', $payload['email'])->sole();

        $this->assertSame(LogisticsProviderStatus::Unverified, $provider->status);
        $this->assertCount(1, $notifier->sent);
    }

    public function test_logistics_failed_resend_keeps_the_previous_code_valid(): void
    {
        $notifier = $this->fakeNotifier();

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload())->assertStatus(201);

        $provider = LogisticsProvider::query()->latest('id')->firstOrFail();
        $originalOtp = $notifier->lastCode();

        $notifier->fail = true;
        $this->postJson(SignedOtpLink::resendUrl('logistics', $provider))->assertStatus(503);
        $notifier->fail = false;

        // The code the user already received must still work.
        $this->postJson(SignedOtpLink::verifyUrl('logistics', $provider), ['otp' => $originalOtp])
            ->assertOk();
    }

    public function test_logistics_successful_resend_invalidates_the_previous_code(): void
    {
        $notifier = $this->fakeNotifier();

        $this->postJson(route('register.logistics.store'), $this->logisticsPayload())->assertStatus(201);

        $provider = LogisticsProvider::query()->latest('id')->firstOrFail();
        $oldOtp = $notifier->lastCode();

        $this->postJson(SignedOtpLink::resendUrl('logistics', $provider))->assertOk();
        $newOtp = $notifier->lastCode();

        $this->assertNotSame($oldOtp, $newOtp);

        // Only the newest code is accepted.
        $this->postJson(SignedOtpLink::verifyUrl('logistics', $provider), ['otp' => $oldOtp])
            ->assertStatus(422);
        $this->postJson(SignedOtpLink::verifyUrl('logistics', $provider), ['otp' => $newOtp])
            ->assertOk();
    }
}
