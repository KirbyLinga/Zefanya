<?php

namespace Tests\Feature\Rider;

use App\Enums\LogisticsProviderStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\RiderApplication;
use App\Models\Seller\Seller;
use App\Notifications\Rider\RiderRegistrationOtp;
use App\Services\RiderRegistrationService;
use App\Support\SignedOtpLink;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RiderRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(array $overrides = []): array
    {
        $provider = LogisticsProvider::factory()->create(['status' => LogisticsProviderStatus::Approved]);

        return array_replace([
            'first_name' => 'Ana', 'last_name' => 'Santos', 'middle_initial' => 'M', 'sex' => 'female',
            'email' => 'ana@example.com', 'password' => 'Password1!', 'password_confirmation' => 'Password1!',
            'contact_no' => '09171234567', 'birthday' => '1995-06-20',
            'logistics_provider_id' => $provider->id, 'address_mode' => 'api',
            'province' => '0400000000', 'province_name' => 'Laguna',
            'municipality' => '0434000000', 'municipality_name' => 'Calamba',
            'barangay' => '043401001', 'barangay_name' => 'Barangay One',
            'street' => 'Rizal Street', 'house_number' => '10', 'address_detail' => 'Near plaza',
            'vehicle_type' => 'motorcycle', 'plate_number' => 'ABC 1234', 'terms' => '1',
            'upload_id' => UploadedFile::fake()->createWithContent('license.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF"),
            'or_cr_upload' => UploadedFile::fake()->createWithContent('or-cr.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF"),
        ], $overrides);
    }

    private function register(array $overrides = []): RiderApplication
    {
        $this->postJson(route('register.rider.store'), $this->payload($overrides))->assertCreated();

        return RiderApplication::query()->sole();
    }

    private function application(string $status = 'unverified'): RiderApplication
    {
        $data = $this->payload();
        $app = new RiderApplication;
        $app->fill(collect($data)->only($app->getFillable())->all());
        $app->forceFill([
            'logistics_provider_id' => $data['logistics_provider_id'], 'status' => $status,
            'province_code' => $data['province'], 'municipality_code' => $data['municipality'], 'barangay_code' => $data['barangay'],
            'upload_id_path' => 'rider-ids/test.pdf', 'or_cr_path' => 'rider-or-cr/test.pdf',
            'email_verified_at' => $status === 'unverified' ? null : now(),
        ])->save();
        $app->commitOtp('123456');

        return $app;
    }

    public function test_registration_uses_selected_provider_private_documents_hashed_password_and_otp_without_creating_courier(): void
    {
        Notification::fake([RiderRegistrationOtp::class]);
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 19));
        $payload = $this->payload(['email' => ' ANA@Example.com ', 'contact_no' => '+63 917 123 4567', 'plate_number' => 'abc 1234', 'age' => 999, 'status' => 'approved']);
        $response = $this->postJson(route('register.rider.store'), $payload)->assertCreated();
        $app = RiderApplication::query()->sole();
        $this->assertSame('unverified', $app->status);
        $this->assertSame((int) $payload['logistics_provider_id'], (int) $app->logistics_provider_id);
        $this->assertSame('ana@example.com', $app->email);
        $this->assertSame('09171234567', $app->contact_no);
        $this->assertSame('ABC 1234', $app->plate_number);
        $this->assertSame(31, $app->age);
        $this->assertTrue(Hash::check('Password1!', $app->password));
        $this->assertFalse($app->isApproved());
        $this->assertDatabaseCount('couriers', 0);
        Storage::disk('local')->assertExists([$app->upload_id_path, $app->or_cr_path]);
        $this->assertArrayNotHasKey('upload_id_path', $app->toArray());
        $this->assertArrayNotHasKey('or_cr_path', $app->toArray());
        Notification::assertSentTo($app, RiderRegistrationOtp::class, function ($notice, $channels) use ($app) {
            $this->assertSame(['mail'], $channels);
            $this->assertTrue($app->otpMatches($notice->otp));
            $this->assertNotSame($notice->otp, $app->email_verification_code);
            $this->assertSame('Your Zefanya Rider verification code', $notice->toMail($app)->subject);

            return true;
        });
        $this->assertStringContainsString('signature=', $response->json('verify_url'));
        $this->assertGuest('rider');
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $this->postJson(route('register.rider.store'), [])->assertUnprocessable()->assertJsonValidationErrors([
            'first_name', 'last_name', 'sex', 'email', 'password', 'contact_no', 'birthday',
            'logistics_provider_id', 'province', 'municipality', 'barangay', 'street',
            'vehicle_type', 'plate_number', 'upload_id', 'or_cr_upload', 'terms',
        ]);
        $this->assertDatabaseCount('rider_applications', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'email' => ['email', 'invalid'], 'contact' => ['contact_no', '12345'],
            'future birthday' => ['birthday', '2100-01-01'], 'impossible birthday' => ['birthday', '1995-02-30'],
            'minor' => ['birthday', '2020-01-01'], 'too old' => ['birthday', '1800-01-01'],
            'vehicle' => ['vehicle_type', 'spaceship'], 'plate punctuation' => ['plate_number', '<script>'],
            'plate without digit' => ['plate_number', 'ABC'], 'plate too long' => ['plate_number', 'ABC12345678901234567890'],
            'terms' => ['terms', '0'], 'password mismatch' => ['password', 'Different1!'],
            'weak password' => ['password', 'password'], 'sex' => ['sex', 'invalid'],
            'manual address' => ['address_mode', 'manual'], 'middle initial' => ['middle_initial', '123'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_fields_are_rejected(string $field, string $value): void
    {
        Notification::fake([RiderRegistrationOtp::class]);
        $this->postJson(route('register.rider.store'), $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('rider_applications', 0);
        Notification::assertNothingSent();
    }

    public static function foreignRoles(): array
    {
        return [['buyer'], ['seller'], ['logistics'], ['admin']];
    }

    #[DataProvider('foreignRoles')]
    public function test_email_is_unique_across_all_existing_roles_case_insensitively(string $role): void
    {
        $model = match ($role) {
            'buyer' => Buyer::class, 'seller' => Seller::class, 'logistics' => LogisticsProvider::class, 'admin' => Admin::class
        };
        if ($role === 'admin') {
            Admin::create(['name' => 'Test Admin', 'email' => 'ANA@example.com', 'password' => 'Password1!']);
        } else {
            $model::factory()->create(['email' => 'ANA@example.com']);
        }
        $this->postJson(route('register.rider.store'), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('rider_applications', 0);
    }

    public function test_duplicate_submissions_and_mobile_numbers_do_not_create_second_application(): void
    {
        Notification::fake([RiderRegistrationOtp::class]);
        Storage::fake('local');
        $app = $this->register();
        $this->postJson(route('register.rider.store'), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson(route('register.rider.store'), $this->payload(['email' => 'other@example.com']))->assertUnprocessable()->assertJsonValidationErrors('contact_no');
        $this->assertDatabaseCount('rider_applications', 1);
        Notification::assertSentToTimes($app, RiderRegistrationOtp::class, 1);
    }

    public function test_provider_must_be_approved_existing_and_not_archived(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => LogisticsProviderStatus::PendingApproval]);
        $payload = $this->payload(['logistics_provider_id' => $provider->id]);
        $this->postJson(route('register.rider.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('logistics_provider_id');
        $provider->delete();
        $this->postJson(route('register.rider.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('logistics_provider_id');
        $payload['logistics_provider_id'] = 999999;
        $this->postJson(route('register.rider.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('logistics_provider_id');
    }

    public static function documents(): array
    {
        return [['upload_id', 'exe', 10, 'application/octet-stream'], ['or_cr_upload', 'svg', 10, 'image/svg+xml'], ['upload_id', 'pdf', 5121, 'application/pdf'], ['or_cr_upload', 'pdf', 5121, 'application/pdf']];
    }

    #[DataProvider('documents')]
    public function test_invalid_or_oversized_documents_are_rejected(string $field, string $extension, int $size, string $mime): void
    {
        $this->postJson(route('register.rider.store'), $this->payload([$field => UploadedFile::fake()->create('document.'.$extension, $size, $mime)]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('rider_applications', 0);
    }

    public function test_mail_failure_rolls_back_application_otp_and_uploaded_files(): void
    {
        Storage::fake('local');
        $this->mock(Dispatcher::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('Transport failed'));
        $this->postJson(route('register.rider.store'), $this->payload())->assertStatus(503);
        $this->assertDatabaseCount('rider_applications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_provider_is_rechecked_at_the_service_boundary(): void
    {
        $data = $this->payload();
        LogisticsProvider::findOrFail($data['logistics_provider_id'])->forceFill(['status' => LogisticsProviderStatus::Rejected])->save();
        $this->expectException(ValidationException::class);
        app(RiderRegistrationService::class)->register($data, $data['upload_id'], $data['or_cr_upload']);
    }

    public function test_signed_otp_verification_moves_only_to_pending_and_clears_secret(): void
    {
        $app = $this->application();
        $this->get(SignedOtpLink::showUrl('rider', $app))->assertOk()->assertSee('Verify your email');
        $this->postJson(SignedOtpLink::verifyUrl('rider', $app), ['otp' => '123456'])->assertOk()->assertJsonPath('redirect', route('register.rider.pending'));
        $app->refresh();
        $this->assertSame('pending', $app->status);
        $this->assertNotNull($app->email_verified_at);
        $this->assertNull($app->email_verification_code);
        $this->assertNull($app->email_verification_expires_at);
        $this->assertDatabaseCount('couriers', 0);
        $this->postJson(SignedOtpLink::verifyUrl('rider', $app), ['otp' => '123456'])->assertOk();
        $this->assertSame('pending', $app->fresh()->status);
    }

    public function test_unsigned_otp_requests_and_tampered_ids_are_forbidden(): void
    {
        $app = $this->application();
        $this->get(route('register.rider.verify-otp.show', $app))->assertForbidden();
        $this->postJson(route('register.rider.verify-otp', $app), ['otp' => '123456'])->assertForbidden();
        $this->postJson(route('register.rider.verify-otp.resend', $app))->assertForbidden();
        $other = $app->replicate();
        $other->forceFill(['email' => 'other@example.com', 'contact_no' => '09179999999'])->save();
        $this->postJson(str_replace('/'.$app->id.'?', '/'.$other->id.'?', SignedOtpLink::verifyUrl('rider', $app)), ['otp' => '123456'])->assertForbidden();
    }

    public function test_expired_and_malformed_otps_do_not_verify(): void
    {
        $app = $this->application();
        $url = SignedOtpLink::verifyUrl('rider', $app);
        $this->postJson($url, ['otp' => 'abc'])->assertUnprocessable();
        $app->forceFill(['email_verification_expires_at' => now()->subMinute()])->save();
        $this->postJson($url, ['otp' => '123456'])->assertUnprocessable();
        $this->assertSame('unverified', $app->fresh()->status);
    }

    public function test_resend_replaces_otp_and_preserves_failure_lock(): void
    {
        Notification::fake([RiderRegistrationOtp::class]);
        $app = $this->application();
        $verify = SignedOtpLink::verifyUrl('rider', $app);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson($verify, ['otp' => '000000'])->assertUnprocessable();
        }
        $this->postJson(SignedOtpLink::resendUrl('rider', $app))->assertOk();
        Notification::assertSentTo($app, RiderRegistrationOtp::class, fn ($notice) => $app->fresh()->otpMatches($notice->otp));
        $this->assertSame(4, (int) $app->fresh()->otp_attempts);
        $this->postJson($verify, ['otp' => '000000'])->assertStatus(429);
        $this->postJson(SignedOtpLink::resendUrl('rider', $app))->assertOk();
        $notice = Notification::sent($app, RiderRegistrationOtp::class)->last();
        $this->postJson($verify, ['otp' => $notice->otp])->assertStatus(429);
    }

    public function test_account_resend_limit_is_three_per_ten_minutes(): void
    {
        Notification::fake([RiderRegistrationOtp::class]);
        $app = $this->application();
        $url = SignedOtpLink::resendUrl('rider', $app);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson($url)->assertOk();
        }
        $this->postJson($url)->assertStatus(429);
        Notification::assertSentToTimes($app, RiderRegistrationOtp::class, 3);
    }

    public function test_failed_resend_preserves_existing_code(): void
    {
        $app = $this->application();
        $this->mock(Dispatcher::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('Transport failed'));
        $this->postJson(SignedOtpLink::resendUrl('rider', $app))->assertStatus(503);
        $this->assertTrue($app->fresh()->otpMatches('123456'));
    }

    public static function blockedStatuses(): array
    {
        return [['unverified'], ['pending'], ['rejected'], ['suspended']];
    }

    #[DataProvider('blockedStatuses')]
    public function test_unapproved_riders_cannot_login_or_access_protected_functions(string $status): void
    {
        $app = $this->application($status);
        $this->postJson(route('unified.login'), ['email' => $app->email, 'password' => 'Password1!'])->assertUnprocessable();
        $this->assertGuest('rider');
        Route::middleware(['web', 'auth:rider', 'rider.approved'])->get('/r01-test-protected', fn () => response()->json(['ok' => true]));
        $this->actingAs($app, 'rider')->getJson('/r01-test-protected')->assertForbidden();
        $this->assertGuest('rider');
    }

    public function test_guest_cannot_access_rider_protected_route_and_verified_approved_identity_can_login(): void
    {
        Route::middleware(['web', 'auth:rider', 'rider.approved'])->get('/r01-test-protected', fn () => response()->json(['ok' => true]));
        $this->getJson('/r01-test-protected')->assertUnauthorized();
        // Fixture represents a future R02 approval, not an R01 approval endpoint.
        $app = $this->application('approved');
        $this->post(route('unified.login'), ['email' => $app->email, 'password' => 'Password1!'])->assertRedirect(route('rider.dashboard'));
        $this->assertAuthenticatedAs($app, 'rider');
        $this->getJson('/r01-test-protected')->assertOk();
        $this->post(route('unified.logout'))->assertRedirect(route('home'));
        $this->assertGuest('rider');
    }

    public function test_only_selected_approved_provider_can_download_verified_applicant_documents(): void
    {
        Storage::fake('local');
        $app = $this->application('pending');
        Storage::disk('local')->put($app->upload_id_path, '%PDF-1.4 document');
        Storage::disk('local')->put($app->or_cr_path, '%PDF-1.4 document');
        $url = route('logistics.rider-applications.documents.show', [$app, 'id']);
        $this->getJson($url)->assertUnauthorized();
        $other = LogisticsProvider::factory()->create(['status' => LogisticsProviderStatus::Approved]);
        $this->actingAs($other, 'logistics')->get($url)->assertNotFound();
        $this->actingAs($app->provider, 'logistics')->get($url)->assertOk()->assertDownload()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('logistics.rider-applications.documents.show', [$app, 'or-cr']))->assertOk()->assertDownload();
        $app->forceFill(['email_verified_at' => null])->save();
        $this->get($url)->assertNotFound();
    }

    public function test_registration_option_shares_existing_steps_and_only_lists_approved_providers(): void
    {
        $approved = LogisticsProvider::factory()->create(['status' => LogisticsProviderStatus::Approved, 'business_name' => 'Visible Approved Fleet']);
        LogisticsProvider::factory()->create(['status' => LogisticsProviderStatus::PendingApproval, 'business_name' => 'Invisible Pending Fleet']);
        $this->get(route('register.type'))->assertOk()->assertSee('data-rider-register-trigger', false)->assertSee('riderFirstName')->assertSee('riderProvince')->assertSee('riderTerms')->assertSee($approved->business_name)->assertDontSee('Invisible Pending Fleet');
        $this->get(route('register.rider'))->assertRedirect(route('register.type', ['open' => 'rider']));
        $this->get(route('register.rider.pending'))->assertOk()->assertSee('Pending Logistics Approval');
    }

    public function test_existing_roles_cannot_register_using_a_rider_application_email(): void
    {
        $app = $this->application('pending');
        foreach (['buyer', 'seller', 'logistics'] as $role) {
            $payload = $role === 'buyer' ? $this->payload(['email' => strtoupper($app->email)]) : ['email' => strtoupper($app->email)];
            $this->postJson(route('register.'.$role.'.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        }
    }

    public function test_adult_birthday_boundary_uses_server_date_and_ignores_submitted_age(): void
    {
        Notification::fake([RiderRegistrationOtp::class]);
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 10, 19));
        $app = $this->register(['birthday' => '2008-10-19', 'age' => 99]);
        $this->assertSame(18, $app->age);
        $this->assertSame('2008-10-19', $app->birthday->toDateString());
    }

    public function test_rejected_application_can_retry_with_archived_audit_and_new_otp(): void
    {
        Notification::fake([RiderRegistrationOtp::class]);
        Storage::fake('local');
        $old = $this->application('rejected');
        Storage::disk('local')->put($old->upload_id_path, 'old id');
        Storage::disk('local')->put($old->or_cr_path, 'old or cr');
        $this->postJson(route('register.rider.store'), $this->payload())->assertCreated();
        $this->assertDatabaseCount('rider_applications', 2);
        $archived = RiderApplication::withoutGlobalScopes()->findOrFail($old->id);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame('ana@example.com', $archived->registration_email);
        $this->assertNull($archived->contact_no);
        $this->assertSame('rejected', $archived->status);
        Storage::disk('local')->assertMissing([$old->upload_id_path, $old->or_cr_path]);
        $new = RiderApplication::query()->sole();
        $this->assertSame('unverified', $new->status);
        Notification::assertSentTo($new, RiderRegistrationOtp::class);
    }

    public function test_abandoned_unverified_application_can_retry_after_expiry(): void
    {
        Notification::fake([RiderRegistrationOtp::class]);
        Storage::fake('local');
        $old = $this->application();
        $old->forceFill(['created_at' => now()->subMinutes(11), 'email_verification_expires_at' => now()->subMinute()])->save();
        $this->postJson(route('register.rider.store'), $this->payload())->assertCreated();
        $this->assertDatabaseCount('rider_applications', 2);
        $new = RiderApplication::query()->sole();
        $this->assertNotSame($old->id, $new->id);
        Notification::assertSentTo($new, RiderRegistrationOtp::class);
    }

    public function test_retry_mail_failure_restores_old_application_and_keeps_its_documents(): void
    {
        Storage::fake('local');
        $old = $this->application('rejected');
        Storage::disk('local')->put($old->upload_id_path, 'old id');
        Storage::disk('local')->put($old->or_cr_path, 'old or cr');
        $this->mock(Dispatcher::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('Transport failed'));
        $this->postJson(route('register.rider.store'), $this->payload())->assertStatus(503);
        $this->assertDatabaseCount('rider_applications', 1);
        $this->assertSame('ana@example.com', $old->fresh()->email);
        $this->assertNull($old->fresh()->archived_at);
        Storage::disk('local')->assertExists([$old->upload_id_path, $old->or_cr_path]);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_registration_rate_limit_blocks_repeated_submissions(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('register.rider.store'), ['email' => 'rate@example.com'])->assertUnprocessable();
        }
        $this->postJson(route('register.rider.store'), ['email' => 'rate@example.com'])->assertStatus(429);
        $this->assertDatabaseCount('rider_applications', 0);
    }

    public function test_approved_status_without_email_verification_cannot_login(): void
    {
        $app = $this->application('approved');
        $app->forceFill(['email_verified_at' => null])->save();
        $this->postJson(route('unified.login'), ['email' => $app->email, 'password' => 'Password1!'])->assertUnprocessable();
        $this->assertGuest('rider');
    }

    public function test_verified_applicants_cannot_request_another_otp(): void
    {
        Notification::fake([RiderRegistrationOtp::class]);
        $app = $this->application('pending');
        $this->postJson(SignedOtpLink::resendUrl('rider', $app))->assertUnprocessable();
        Notification::assertNothingSent();
    }

    public function test_admin_cannot_download_rider_documents(): void
    {
        $app = $this->application('pending');
        $admin = Admin::create(['name' => 'Test Admin', 'email' => 'admin@example.com', 'password' => 'Password1!']);
        $this->actingAs($admin, 'admin')->getJson(route('logistics.rider-applications.documents.show', [$app, 'id']))->assertUnauthorized();
    }

    public function test_suspended_provider_is_logged_out_before_document_access(): void
    {
        $app = $this->application('pending');
        $provider = $app->provider;
        $provider->forceFill(['status' => LogisticsProviderStatus::Rejected])->save();
        $this->actingAs($provider, 'logistics')->getJson(route('logistics.rider-applications.documents.show', [$app, 'id']))->assertRedirect(route('login'));
        $this->assertGuest('logistics');
    }

    public function test_malformed_non_scalar_registration_fields_are_validation_errors(): void
    {
        $payload = $this->payload(['email' => ['invalid'], 'contact_no' => ['invalid'], 'plate_number' => ['invalid']]);
        $this->postJson(route('register.rider.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors(['email', 'contact_no', 'plate_number']);
        $this->assertDatabaseCount('rider_applications', 0);
    }

    public function test_malformed_non_scalar_otp_is_rejected_without_server_error(): void
    {
        $app = $this->application();
        $this->postJson(SignedOtpLink::verifyUrl('rider', $app), ['otp' => ['123456']])->assertUnprocessable();
        $this->assertSame('unverified', $app->fresh()->status);
    }

    public function test_approved_rider_login_normalizes_email_case(): void
    {
        $app = $this->application('approved');
        $this->postJson(route('unified.login'), ['email' => strtoupper($app->email), 'password' => 'Password1!'])->assertRedirect(route('rider.dashboard'));
        $this->assertAuthenticatedAs($app, 'rider');
    }

    public function test_signed_in_rider_cannot_submit_another_registration(): void
    {
        $app = $this->application('approved');
        $this->actingAs($app, 'rider')->postJson(route('register.rider.store'), [])->assertStatus(409)->assertJsonPath('redirect', route('rider.dashboard'));
        $this->assertDatabaseCount('rider_applications', 1);
    }

    public function test_native_form_submission_and_verification_redirect_to_existing_flow(): void
    {
        Notification::fake([RiderRegistrationOtp::class]);
        Storage::fake('local');
        $this->post(route('register.rider.store'), $this->payload())->assertRedirect();
        $app = RiderApplication::query()->sole();
        $notice = Notification::sent($app, RiderRegistrationOtp::class)->sole();
        $this->post(SignedOtpLink::verifyUrl('rider', $app), ['otp' => $notice->otp])->assertRedirect(route('register.rider.pending'));
        $this->assertSame('pending', $app->fresh()->status);
    }

    public function test_filename_extension_and_file_contents_must_both_be_allowed(): void
    {
        $file = UploadedFile::fake()->createWithContent('license.jpg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->postJson(route('register.rider.store'), $this->payload(['upload_id' => $file]))->assertUnprocessable()->assertJsonValidationErrors('upload_id');
        $this->assertDatabaseCount('rider_applications', 0);
    }
}
