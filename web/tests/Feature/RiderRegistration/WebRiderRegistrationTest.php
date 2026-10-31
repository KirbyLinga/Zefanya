<?php

namespace Tests\Feature\RiderRegistration;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Notifications\Rider\RiderRegistrationOtp;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebRiderRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(LogisticsProvider $provider): array
    {
        return [
            'first_name' => 'Pedro Jose', 'last_name' => 'Garcia', 'birthday' => '1995-03-22',
            'email' => 'web.rider@example.com', 'contact_no' => '09451239876',
            'password' => 'Rider@2026', 'password_confirmation' => 'Rider@2026',
            'license_no' => 'N01-23-456789', 'license_expiry' => now()->addYear()->toDateString(),
            'vehicle_type' => 'Motorcycle', 'plate_number' => 'ABC1234',
            'logistics_provider_id' => $provider->id, 'terms' => true,
            'address_mode' => 'api', 'province' => '030540000', 'province_name' => 'Pampanga',
            'municipality' => '030540100', 'municipality_name' => 'San Fernando',
            'barangay' => '0305401001', 'barangay_name' => 'Barangay 1', 'street' => '123 Rizal Street',
            'drivers_license' => UploadedFile::fake()->createWithContent('license.pdf', "%PDF-1.4\nLicense"),
            'or_cr' => UploadedFile::fake()->createWithContent('or-cr.pdf', "%PDF-1.4\nRegistration"),
        ];
    }

    public function test_register_page_contains_all_four_options_and_rider_required_fields(): void
    {
        $this->withoutVite();
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'business_name' => 'Approved Logistics']);
        LogisticsProvider::factory()->create(['status' => 'pending', 'business_name' => 'Pending Logistics']);
        $response = $this->get(route('register.type'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);

        foreach (['buyer', 'seller', 'logistics', 'rider'] as $role) {
            $this->assertSame(1, $xpath->query('//*[@data-'.$role.'-register-trigger]')->length);
        }
        $this->assertSame(1, $xpath->query('//*[@id="riderModalOverlay"]//*[@role="dialog"]')->length);
        foreach (['first_name', 'last_name', 'middle_initial', 'sex', 'birthday', 'email', 'contact_no', 'password', 'password_confirmation', 'address_mode', 'province', 'municipality', 'barangay', 'street', 'house_number', 'address_detail', 'postal_code', 'license_no', 'license_expiry', 'vehicle_type', 'plate_number', 'logistics_provider_id', 'drivers_license', 'or_cr', 'terms'] as $field) {
            $this->assertSame(1, $xpath->query('//*[@id="riderRegisterForm"]//*[@name="'.$field.'"]')->length, $field);
        }
        $this->assertSame(4, $xpath->query('//*[@id="riderStepper"]//*[@data-rider-step-circle]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="riderRegisterForm"]//*[@id="riderNextStep1" and contains(@class,"w-full")]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="riderReviewEmail"]')->length);
        $this->assertSame(6, $xpath->query('//*[@id="riderOtpInputs"]//*[@data-rider-otp-index]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="riderRegisterForm"]//*[@id="riderAge" and @readonly]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="riderLogisticsProvider"]//option[@value="'.$provider->id.'"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="riderModalOverlay" and @data-resume-url]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="riderRegisterForm"]//*[@id="riderResumeBtn"]')->length);
        $response->assertSee('Approved Logistics')->assertDontSee('Pending Logistics');
    }

    public function test_registration_page_disables_rider_submission_when_no_approved_provider_exists(): void
    {
        $this->withoutVite();
        $response = $this->get(route('register.type'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);

        $this->assertSame(1, $xpath->query('//*[@data-rider-register and @disabled]')->length);
        $response->assertSee('No logistics providers are currently available.');
    }

    public function test_rider_direct_link_returns_to_the_existing_registration_page(): void
    {
        $this->get(route('register.rider'))->assertRedirect(route('register.type', ['open' => 'rider']));
    }

    public function test_web_form_submits_and_verifies_to_pending_without_a_rider_session(): void
    {
        Storage::fake('local');
        Notification::fake();
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $response = $this->postJson(route('register.rider.store'), $this->payload($provider))->assertCreated()->assertJsonPath('data.status', 'unverified');
        $rider = Rider::findOrFail($response->json('data.rider_id'));
        $otp = '';
        Notification::assertSentTo($rider, RiderRegistrationOtp::class, function ($notification) use (&$otp, $rider): bool {
            $otp = $notification->otp;
            $this->assertContains('Enter this code on the registration screen to verify your email:', $notification->toMail($rider)->introLines);

            return true;
        });

        $this->postJson($response->json('data.verify_url'), ['otp' => $otp])->assertOk()->assertJsonPath('data.status', 'pending');
        $this->get(route('register.type'))->assertSee('Your Rider registration has been submitted. Please wait for Logistics approval.');
        $this->assertSame('pending', $rider->fresh()->status);
        $this->assertTrue(Hash::check('Rider@2026', $rider->password));
        $this->assertNotNull($rider->fresh()->email_verified_at);
        $this->assertGuest('rider');
        Storage::disk('local')->assertExists([$rider->drivers_license_path, $rider->or_cr_path]);
    }

    public function test_web_form_returns_field_errors_for_email_owned_by_another_role(): void
    {
        Storage::fake('local');
        Notification::fake();
        Buyer::factory()->create(['email' => 'web.rider@example.com']);
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);

        $this->postJson(route('register.rider.store'), $this->payload($provider))->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->assertDatabaseCount('riders', 0);
        Notification::assertNothingSent();
    }

    public function test_web_form_missing_fields_returns_modal_compatible_json_errors(): void
    {
        $this->postJson(route('register.rider.store'))->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'drivers_license', 'or_cr', 'address_mode', 'province', 'municipality', 'barangay', 'street']);
        $this->assertDatabaseCount('riders', 0);
    }

    public function test_web_registration_uses_existing_registration_throttling(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('register.rider.store'), ['email' => 'limited@example.com'])->assertUnprocessable();
        }

        $this->postJson(route('register.rider.store'), ['email' => 'limited@example.com'])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('riders', 0);
    }

    public function test_web_registration_requires_csrf_protection(): void
    {
        $this->app->instance('env', 'production');

        $this->postJson(route('register.rider.store'))->assertStatus(419);
        $this->assertDatabaseCount('riders', 0);
    }
}
