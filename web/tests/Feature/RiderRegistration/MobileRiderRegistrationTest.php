<?php

namespace Tests\Feature\RiderRegistration;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Notifications\Rider\RiderRegistrationOtp;
use App\Support\EmailRegistry;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileRiderRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Pedro Jose', 'last_name' => 'Garcia',
            'birthday' => '1995-03-22', 'email' => 'mobile.rider@example.com',
            'contact_no' => '+63 945 123 9876', 'license_no' => 'N01-23-456789',
            'license_expiry' => now()->addYears(2)->toDateString(),
            'vehicle_type' => 'Motorcycle', 'plate_number' => 'abc1234',
            'drivers_license' => UploadedFile::fake()->createWithContent('license.pdf', "%PDF-1.4\nDriver license"),
            'or_cr' => UploadedFile::fake()->createWithContent('registration.pdf', "%PDF-1.4\nVehicle registration"),
            'password' => 'Rider@2026', 'password_confirmation' => 'Rider@2026',
            'logistics_provider_id' => LogisticsProvider::factory()->create(['status' => 'approved'])->id,
            'address_mode' => 'api', 'province' => '030540000', 'province_name' => 'Pampanga',
            'municipality' => '030540100', 'municipality_name' => 'San Fernando',
            'barangay' => '0305401001', 'barangay_name' => 'Barangay 1', 'street' => '123 Rizal Street',
            'terms' => true,
        ], $overrides);
    }

    private function register(array $overrides = []): array
    {
        Storage::fake('local');
        Notification::fake();
        $response = $this->postJson('/api/rider/register', $this->payload($overrides))->assertCreated();

        return [Rider::findOrFail($response->json('data.rider_id')), $response->json('data')];
    }

    private function otp(Rider $rider): string
    {
        $otp = '';
        Notification::assertSentTo($rider, RiderRegistrationOtp::class, function ($notification) use (&$otp): bool {
            $otp = $notification->otp;

            return true;
        });

        return $otp;
    }

    private function pending(): array
    {
        [$rider, $data] = $this->register();
        $this->postJson($data['verify_url'], ['otp' => $this->otp($rider)])->assertOk()->assertJsonPath('data.status', 'pending');

        return [$rider->refresh(), $data];
    }

    private function stationFor(LogisticsProvider $provider): LogisticsHub
    {
        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => 'Test Province Hub '.$provider->id,
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);

        return LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => 'Test Station Hub '.$provider->id,
            'hub_type' => LogisticsHub::STATION,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'city_municipality_code' => '030540100',
            'municipality' => 'San Fernando',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);
    }

    public function test_mobile_registration_stores_hashed_credentials_and_private_documents(): void
    {
        [$rider, $data] = $this->register(['email' => ' MOBILE.RIDER@EXAMPLE.COM ', 'status' => 'approved', 'decided_by' => 999]);

        $this->assertSame('unverified', $rider->status);
        $this->assertSame('mobile.rider@example.com', $rider->email);
        $this->assertSame('09451239876', $rider->contact_no);
        $this->assertSame('ABC1234', $rider->plate_number);
        $this->assertTrue(Hash::check('Rider@2026', $rider->password));
        $this->assertNull($rider->decided_by);
        $this->assertNull($rider->email_verified_at);
        $this->assertSame(Rider::hashOtp($this->otp($rider)), $rider->email_verification_code);
        Storage::disk('local')->assertExists([$rider->drivers_license_path, $rider->or_cr_path]);
        $this->assertSame(['rider'], EmailRegistry::rolesFor($rider->email));
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('email_verification_code', $rider->toArray());
        $this->assertGuest('rider');
        $this->assertDatabaseCount('rider_activation_tokens', 0);
        $this->assertDatabaseCount('couriers', 0);
    }

    public function test_missing_fields_return_json_validation_errors_without_an_accept_header(): void
    {
        $response = $this->post('/api/rider/register');

        $response->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password', 'birthday', 'contact_no', 'license_no', 'license_expiry', 'vehicle_type', 'plate_number', 'drivers_license', 'or_cr', 'logistics_provider_id', 'terms', 'address_mode', 'province', 'municipality', 'barangay', 'street']);
        $this->assertDatabaseCount('riders', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'minor' => ['birthday', '2020-01-01'],
            'expired license' => ['license_expiry', '2000-01-01'],
            'bad mobile' => ['contact_no', '12345'],
            'bad license' => ['license_no', 'invalid'],
            'bad vehicle' => ['vehicle_type', 'Bicycle'],
            'bad email' => ['email', 'invalid'],
            'weak password' => ['password', 'password'],
            'overlong password' => ['password', 'Rider@2026'.str_repeat('x', 80)],
            'multibyte overlong password' => ['password', 'Rider@2026'.str_repeat("\u{00F1}", 35)],
            'null in password' => ['password', "Rider@2026\0bad"],
            'confirmation' => ['password_confirmation', 'Different@2026'],
            'terms declined' => ['terms', false],
            'name array' => ['first_name', ['malformed']],
            'email array' => ['email', ['malformed']],
            'phone array' => ['contact_no', ['malformed']],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_fields_return_422_without_saving_an_application(string $field, mixed $value): void
    {
        Storage::fake('local');
        Notification::fake();

        $overrides = [$field => $value];
        if ($field === 'password') {
            $overrides['password_confirmation'] = $value;
        }

        $this->postJson('/api/rider/register', $this->payload($overrides))
            ->assertUnprocessable()->assertJsonValidationErrors([$field === 'password_confirmation' ? 'password' : $field]);
        $this->assertDatabaseCount('riders', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_executable_disguised_as_a_document_is_rejected(): void
    {
        Storage::fake('local');
        $payload = $this->payload(['drivers_license' => UploadedFile::fake()->createWithContent('license.jpg', 'MZ executable')]);

        $this->postJson('/api/rider/register', $payload)->assertUnprocessable()->assertJsonValidationErrors(['drivers_license']);
        $this->assertDatabaseCount('riders', 0);
    }

    public function test_documents_larger_than_five_megabytes_are_rejected(): void
    {
        $payload = $this->payload(['or_cr' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')]);

        $this->postJson('/api/rider/register', $payload)->assertUnprocessable()->assertJsonValidationErrors(['or_cr']);
        $this->assertDatabaseCount('riders', 0);
    }

    public static function accountRoles(): array
    {
        return ['buyer' => [Buyer::class], 'seller' => [Seller::class], 'logistics' => [LogisticsProvider::class], 'admin' => [Admin::class], 'rider' => [Rider::class]];
    }

    #[DataProvider('accountRoles')]
    public function test_email_owned_by_any_account_role_is_rejected_case_insensitively(string $model): void
    {
        $model::factory()->create(['email' => 'TAKEN@example.com']);
        $payload = $this->payload(['email' => ' taken@EXAMPLE.com ']);

        $this->postJson('/api/rider/register', $payload)->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->assertDatabaseMissing('riders', ['email' => 'taken@example.com']);
    }

    public function test_duplicate_registration_does_not_replace_the_original_application(): void
    {
        [$rider] = $this->register();
        $hash = $rider->password;

        $this->postJson('/api/rider/register', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['email', 'contact_no', 'license_no', 'plate_number']);
        $this->assertDatabaseCount('riders', 1);
        $this->assertSame($hash, $rider->fresh()->password);
    }

    public function test_duplicate_license_error_points_to_the_resend_recovery(): void
    {
        $this->register();

        $response = $this->postJson('/api/rider/register', $this->payload([
            'email' => 'second.rider@example.com',
            'contact_no' => '09451239877',
            'plate_number' => 'XYZ9999',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['license_no']);

        $this->assertStringContainsString('Already applied? Resend code', $response->json('errors.license_no.0'));
        $this->assertDatabaseCount('riders', 1);
    }

    public function test_nonapproved_logistics_provider_cannot_receive_applications(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'pending']);

        $this->postJson('/api/rider/register', $this->payload(['logistics_provider_id' => $provider->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['logistics_provider_id']);
        $this->assertDatabaseCount('riders', 0);
    }

    public function test_registration_is_throttled(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/rider/register', ['email' => 'throttled@example.com'])->assertUnprocessable();
        }

        $this->postJson('/api/rider/register', ['email' => 'throttled@example.com'])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertDatabaseCount('riders', 0);
    }

    public function test_correct_otp_sets_pending_and_clears_the_code_without_authenticating(): void
    {
        [$rider, $data] = $this->register();

        $this->postJson($data['verify_url'], ['otp' => $this->otp($rider)])
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertNotNull($rider->refresh()->email_verified_at);
        $this->assertNull($rider->email_verification_code);
        $this->assertNull($rider->email_verification_expires_at);
        $this->assertGuest('rider');
    }

    public function test_wrong_and_expired_codes_leave_the_application_unverified(): void
    {
        [$rider, $data] = $this->register();
        $rider->commitOtp('123456');

        $this->postJson($data['verify_url'], ['otp' => '654321'])->assertUnprocessable()->assertJsonValidationErrors(['otp']);
        $this->travel(11)->minutes();
        $this->postJson($data['verify_url'], ['otp' => '123456'])->assertUnprocessable()
            ->assertJsonPath('errors.otp.0', 'This code has expired. Tap "Resend code" for a new one.');
        $this->assertSame('unverified', $rider->fresh()->status);
    }

    public function test_malformed_otp_is_rejected(): void
    {
        [$rider, $data] = $this->register();

        foreach ([['123456'], '', '12345', '1234567', 'abcdef'] as $otp) {
            $this->postJson($data['verify_url'], ['otp' => $otp])->assertUnprocessable()
                ->assertJsonPath('errors.otp.0', 'Enter the 6-digit code from your email.');
        }
        $this->assertSame('unverified', $rider->fresh()->status);
    }

    public function test_five_wrong_codes_lock_verification_and_resending_does_not_unlock_it(): void
    {
        [$rider, $data] = $this->register();
        $rider->commitOtp('123456');
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson($data['verify_url'], ['otp' => '000000'])->assertUnprocessable();
        }
        $this->postJson($data['verify_url'], ['otp' => '000000'])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->postJson($data['resend_url'])->assertOk();
        $this->postJson($data['verify_url'], ['otp' => '123456'])->assertTooManyRequests();
        $this->assertSame('unverified', $rider->fresh()->status);
    }

    public function test_verification_failures_persist_with_the_database_cache_store(): void
    {
        [$rider, $data] = $this->register();
        $rider->commitOtp('123456');
        \Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(Cache::store('database')));
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson($data['verify_url'], ['otp' => '000000'])->assertUnprocessable();
        }
        $this->postJson($data['verify_url'], ['otp' => '000000'])->assertTooManyRequests();
        $this->postJson($data['verify_url'], ['otp' => '123456'])->assertTooManyRequests();
        $this->assertSame(5, $rider->fresh()->otp_attempts);
        $this->assertSame('unverified', $rider->fresh()->status);
    }

    public function test_resend_rotates_the_code_and_is_limited_to_three_per_ten_minutes(): void
    {
        [$rider, $data] = $this->register();
        $original = $rider->email_verification_code;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson($data['resend_url'])->assertOk();
        }
        $this->assertNotSame($original, $rider->fresh()->email_verification_code);
        $this->postJson($data['resend_url'])->assertTooManyRequests()->assertHeader('Retry-After');
        Notification::assertSentToTimes($rider, RiderRegistrationOtp::class, 4);
    }

    public function test_verification_capabilities_cannot_be_tampered_with_or_used_after_expiry(): void
    {
        [$rider, $data] = $this->register();
        $this->postJson(route('api.rider.verify', $rider), ['otp' => $this->otp($rider)])->assertForbidden();
        $this->postJson($data['verify_url'].'tamper', ['otp' => '123456'])->assertForbidden();
        $this->travel(61)->minutes();
        $this->postJson($data['resend_url'])->assertForbidden();
        $this->assertSame('unverified', $rider->fresh()->status);
    }

    public function test_verified_applications_cannot_be_reverified_or_resent(): void
    {
        [$rider, $data] = $this->pending();

        $this->postJson($data['verify_url'], ['otp' => '123456'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->postJson($data['resend_url'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertSame('pending', $rider->fresh()->status);
    }

    public function test_registration_email_failure_rolls_back_the_rider_and_private_uploads(): void
    {
        Storage::fake('local');
        $this->mock(Dispatcher::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));

        $this->postJson('/api/rider/register', $this->payload())->assertServiceUnavailable()->assertJsonPath('message', 'We could not send your verification email. Please try again.');
        $this->assertDatabaseCount('riders', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_resend_email_failure_preserves_the_previous_code(): void
    {
        [$rider, $data] = $this->register();
        $oldCode = $rider->email_verification_code;
        $this->mock(Dispatcher::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));

        $this->postJson($data['resend_url'])->assertServiceUnavailable();
        $this->assertSame($oldCode, $rider->fresh()->email_verification_code);
    }

    public function test_owning_logistics_provider_can_approve_a_verified_pending_application(): void
    {
        [$rider] = $this->pending();
        $station = $this->stationFor($rider->provider);

        $this->actingAs($rider->provider, 'logistics')->postJson(route('api.logistics.rider-applications.approve', $rider), ['station_hub_id' => $station->id])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.station_hub_id', $station->id);
        $this->assertDatabaseHas('riders', ['id' => $rider->id, 'status' => 'approved', 'station_hub_id' => $station->id, 'decided_by' => $rider->logistics_provider_id]);
        $this->assertNotNull($rider->refresh()->decided_at);
        $this->assertDatabaseHas('couriers', ['rider_id' => $rider->id, 'logistics_provider_id' => $rider->logistics_provider_id]);
        $this->assertTrue($rider->fresh()->isEligibleForAssignment());
    }

    public function test_rejection_requires_a_reason_and_preserves_the_decision(): void
    {
        [$rider, $data] = $this->pending();
        $url = route('api.logistics.rider-applications.reject', $rider);
        $this->actingAs($rider->provider, 'logistics')->postJson($url)->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson($url, ['reason' => 'Document is unreadable.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('riders', ['id' => $rider->id, 'status' => 'rejected', 'rejection_reason' => 'Document is unreadable.', 'decided_by' => $rider->logistics_provider_id]);
        $this->postJson(route('api.logistics.rider-applications.approve', $rider))->assertUnprocessable();
        $this->postJson($data['verify_url'], ['otp' => '123456'])->assertUnprocessable();
        $this->assertSame('rejected', $rider->fresh()->status);
    }

    public function test_unverified_riders_cannot_be_approved(): void
    {
        [$rider] = $this->register();

        $this->actingAs($rider->provider, 'logistics')->postJson(route('api.logistics.rider-applications.approve', $rider))
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->assertSame('unverified', $rider->fresh()->status);
    }

    public function test_review_is_scoped_to_the_owning_provider(): void
    {
        [$rider] = $this->pending();
        $other = LogisticsProvider::factory()->create(['status' => 'approved']);

        $this->actingAs($other, 'logistics')->postJson(route('api.logistics.rider-applications.approve', $rider))->assertNotFound();
        $this->postJson(route('api.logistics.rider-applications.reject', $rider), ['reason' => 'Invalid'])->assertNotFound();
        $this->getJson(route('api.logistics.rider-applications.index'))->assertJsonCount(0, 'data');
        $this->getJson(route('api.logistics.rider-applications.document', [$rider, 'drivers_license']))->assertNotFound();
        $this->assertSame('pending', $rider->fresh()->status);
    }

    public static function nonLogisticsRoles(): array
    {
        return ['buyer' => [Buyer::class, 'buyer'], 'seller' => [Seller::class, 'seller'], 'admin' => [Admin::class, 'admin'], 'rider' => [Rider::class, 'rider']];
    }

    #[DataProvider('nonLogisticsRoles')]
    public function test_other_roles_cannot_approve_rider_applications(string $model, string $guard): void
    {
        [$rider] = $this->pending();
        $account = $model::factory()->create();

        $this->actingAs($account, $guard)->postJson(route('api.logistics.rider-applications.approve', $rider))->assertUnauthorized();
        $this->assertSame('pending', $rider->fresh()->status);
    }

    public function test_guests_and_unapproved_logistics_cannot_review_applications(): void
    {
        [$rider] = $this->pending();
        $url = route('api.logistics.rider-applications.approve', $rider);
        $this->postJson($url)->assertUnauthorized();
        $rider->provider->forceFill(['status' => 'pending'])->save();

        $this->actingAs($rider->provider, 'logistics')->postJson($url)->assertForbidden();
        $this->assertSame('pending', $rider->fresh()->status);
    }

    public function test_owning_provider_can_list_pending_applications_and_download_private_documents(): void
    {
        [$rider] = $this->pending();
        $this->actingAs($rider->provider, 'logistics')->getJson(route('api.logistics.rider-applications.index'))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rider->id)
            ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.drivers_license_path');
        $this->getJson(route('api.logistics.rider-applications.document', [$rider, 'drivers_license']))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertStreamedContent("%PDF-1.4\nDriver license");
        $this->get('/storage/'.$rider->drivers_license_path)->assertNotFound();
    }

    public function test_mobile_applicants_cannot_use_legacy_activation_links_to_bypass_review(): void
    {
        [$rider] = $this->pending();
        $this->actingAs($rider->provider, 'logistics')->postJson(route('logistics.riders.resend', $rider))->assertForbidden();
        $token = 'forged-legacy-token';
        DB::table('rider_activation_tokens')->insert(['rider_id' => $rider->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson(route('rider.activate', $token), ['password' => 'Changed@2026', 'password_confirmation' => 'Changed@2026'])->assertForbidden();
        $this->assertSame('pending', $rider->fresh()->status);
        $this->assertTrue(Hash::check('Rider@2026', $rider->password));
    }

    public function test_pending_riders_cannot_access_existing_rider_protected_routes(): void
    {
        [$rider] = $this->pending();
        $this->actingAs($rider, 'rider')->get(route('rider.dashboard'))->assertRedirect();
        $this->assertGuest('rider');
        $this->assertSame('pending', $rider->fresh()->status);
    }

    public function test_expired_registration_urls_can_be_recovered_with_the_registration_password(): void
    {
        [$rider, $data] = $this->register();
        $this->travel(61)->minutes();
        $response = $this->postJson('/api/rider/registration/resume', ['email' => strtoupper($rider->email), 'password' => 'Rider@2026'])
            ->assertOk()->assertJsonPath('data.status', 'unverified');
        $this->postJson($response->json('data.resend_url'))->assertOk();
        $this->assertGuest('rider');
        Notification::assertSentToTimes($rider, RiderRegistrationOtp::class, 2);
    }

    public function test_registration_resume_rejects_wrong_credentials_unknown_email_and_verified_riders(): void
    {
        [$rider] = $this->pending();
        foreach ([['email' => $rider->email, 'password' => 'wrong'], ['email' => 'unknown@example.com', 'password' => 'Rider@2026'], ['email' => $rider->email, 'password' => 'Rider@2026']] as $credentials) {
            $this->postJson('/api/rider/registration/resume', $credentials)->assertUnprocessable()->assertJsonValidationErrors(['email']);
        }
        $this->assertGuest('rider');
    }

    public function test_decision_requests_require_csrf_protection_with_a_real_session(): void
    {
        [$rider] = $this->pending();
        $this->actingAs($rider->provider, 'logistics');
        $this->app->instance('env', 'production');

        $this->postJson(route('api.logistics.rider-applications.approve', $rider))->assertStatus(419);
        $this->assertSame('pending', $rider->fresh()->status);
    }

    public function test_existing_buyer_registration_rejects_a_rider_email(): void
    {
        [$rider] = $this->register();
        $payload = [
            'first_name' => 'Juan Miguel', 'last_name' => 'Dela Cruz', 'sex' => 'male',
            'email' => $rider->email, 'password' => 'Mamimili@2026', 'password_confirmation' => 'Mamimili@2026',
            'contact_no' => '09171234567', 'birthday' => '1998-05-14',
            'address_mode' => 'api', 'province' => '0300000000', 'province_name' => 'Batangas',
            'municipality' => '0306800000', 'municipality_name' => 'Lipa City',
            'barangay' => '030680001', 'barangay_name' => 'Barangay 1', 'street' => '123 Rizal St.', 'terms' => true,
            'upload_id' => UploadedFile::fake()->createWithContent('id.pdf', "%PDF-1.4\nID"),
        ];

        $this->postJson(route('register.buyer.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->assertDatabaseMissing('buyers', ['email' => $rider->email]);
    }
}
