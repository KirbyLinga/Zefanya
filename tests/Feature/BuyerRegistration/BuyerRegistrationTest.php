<?php

namespace Tests\Feature\BuyerRegistration;

use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerVerifyOtp;
use App\Support\SignedOtpLink;
use Carbon\Carbon;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BuyerRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fakeId(string $name = 'valid-id.pdf', int $kilobytes = 100): UploadedFile
    {
        Storage::fake('local');

        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    private function basePayload(array $overrides = []): array
    {
        Carbon::setTestNow('2026-10-08 12:00:00');

        return array_merge([
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'middle_initial' => 'M',
            'sex' => 'male',
            'email' => 'buyer'.uniqid().'@example.com',
            'password' => 'Mamimili@2026',
            'password_confirmation' => 'Mamimili@2026',
            'contact_no' => '09171234567',
            'birthday' => '1998-05-14',
            'address_mode' => 'api',
            'province' => '0300000000',
            'province_name' => 'Batangas',
            'municipality' => '0306800000',
            'municipality_name' => 'Lipa City',
            'barangay' => '030680001',
            'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal St., Purok 2',
            'upload_id' => $this->fakeId(),
            'terms' => '1',
        ], $overrides);
    }

    private function registerBuyer(array $payload = []): TestResponse
    {
        return $this->postJson(route('register.buyer.store'), $this->basePayload($payload));
    }

    private function registerAndCaptureOtp(array $payload = []): array
    {
        Notification::fake();

        $response = $this->registerBuyer($payload);
        $buyer = Buyer::query()->latest('id')->firstOrFail();

        $otp = null;
        Notification::assertSentTo($buyer, BuyerVerifyOtp::class, function ($notification) use (&$otp): bool {
            $otp = $notification->code;

            return true;
        });

        return [$buyer, $otp, $response];
    }

    private function verifyOtp(Buyer $buyer, string $otp): TestResponse
    {
        return $this->postJson(SignedOtpLink::verifyUrl('buyer', $buyer), ['otp' => $otp]);
    }

    private function createUnverifiedBuyer(array $overrides = []): Buyer
    {
        $otp = (string) random_int(100000, 999999);
        Carbon::setTestNow('2026-10-08 12:00:00');

        return Buyer::create(array_merge([
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'middle_initial' => 'M',
            'sex' => 'male',
            'email' => 'juanmiguel.delacruz@example.com',
            'password' => Hash::make('Mamimili@2026'),
            'contact_no' => '09171234567',
            'birthday' => '1998-05-14',
            'age' => 28,
            'address_mode' => 'api',
            'province_code' => '0300000000',
            'province_name' => 'Batangas',
            'municipality_code' => '0306800000',
            'municipality_name' => 'Lipa City',
            'barangay_code' => '030680001',
            'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal St.',
            'upload_id_path' => 'ids/test.pdf',
            'email_verification_code' => Buyer::hashOtp($otp),
            'email_verification_expires_at' => now()->addMinutes(10),
            'status' => 'unverified',
        ], $overrides));
    }

    // ── TC-BUY-001: All valid data ─────────────────────────────────────────

    public function test_buy_001_register_with_all_valid_data(): void
    {
        Notification::fake();

        $response = $this->registerBuyer();
        $buyer = Buyer::query()->latest('id')->firstOrFail();

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('email', $buyer->email);

        $this->assertSame('unverified', $buyer->fresh()->status);
        $this->assertNull($buyer->fresh()->email_verified_at);

        Notification::assertSentTo($buyer, BuyerVerifyOtp::class);
    }

    // ── TC-BUY-002: Empty form ──────────────────────────────────────────────

    public function test_buy_002_submit_empty_form(): void
    {
        $response = $this->postJson(route('register.buyer.store'), []);

        $response->assertStatus(422);
        $errors = $response->json('errors');

        $this->assertArrayHasKey('last_name', $errors);
        $this->assertArrayHasKey('first_name', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('password', $errors);
        $this->assertArrayHasKey('contact_no', $errors);
        $this->assertArrayHasKey('birthday', $errors);
        $this->assertArrayHasKey('address_mode', $errors);
        $this->assertArrayHasKey('street', $errors);
        $this->assertArrayHasKey('upload_id', $errors);
        $this->assertArrayHasKey('terms', $errors);

        $this->assertSame(0, Buyer::count());
    }

    // ── TC-BUY-003: One required field blank at a time ──────────────────────

    public function test_buy_003_first_name_blank(): void
    {
        $response = $this->registerBuyer(['first_name' => '']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('first_name');
        $this->assertSame(0, Buyer::count());
    }

    public function test_buy_003_last_name_blank(): void
    {
        $response = $this->registerBuyer(['last_name' => '']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('last_name');
        $this->assertSame(0, Buyer::count());
    }

    public function test_buy_003_email_blank(): void
    {
        $response = $this->registerBuyer(['email' => '']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
        $this->assertSame(0, Buyer::count());
    }

    public function test_buy_003_mobile_blank(): void
    {
        $response = $this->registerBuyer(['contact_no' => '']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('contact_no');
        $this->assertSame(0, Buyer::count());
    }

    public function test_buy_003_dob_blank(): void
    {
        $response = $this->registerBuyer(['birthday' => '']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('birthday');
        $this->assertSame(0, Buyer::count());
    }

    public function test_buy_003_password_blank(): void
    {
        $response = $this->registerBuyer(['password' => '', 'password_confirmation' => '']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('password');
        $this->assertSame(0, Buyer::count());
    }

    public function test_buy_003_confirm_password_blank(): void
    {
        $response = $this->registerBuyer(['password_confirmation' => '']);
        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertArrayHasKey('password', $errors);
        $this->assertSame(0, Buyer::count());
    }

    // ── TC-BUY-004: Name with numbers or symbols ────────────────────────────

    public function test_buy_004_name_with_numbers_or_symbols(): void
    {
        // Form request now enforces letters-only regex with min:2, max:50
        $response = $this->registerBuyer([
            'first_name' => 'Juan Miguel123',
            'last_name' => 'Dela Cruz@#',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('first_name');
        $response->assertJsonValidationErrorFor('last_name');
    }

    // ── TC-BUY-005: Name with ñ and allowed punctuation ─────────────────────

    public function test_buy_005_name_with_ñ_and_punctuation(): void
    {
        $response = $this->registerBuyer([
            'first_name' => 'Niño Angelo',
            'last_name' => "Peñaflor-O'Hara",
        ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);

        $buyer = Buyer::query()->latest('id')->firstOrFail();
        $this->assertSame('Niño Angelo', $buyer->first_name);
        $this->assertSame("Peñaflor-O'Hara", $buyer->last_name);
    }

    // ── TC-BUY-006: Name length at the limits ───────────────────────────────

    public function test_buy_006_1_char_first_name_rejected(): void
    {
        // The form request now requires min:2, max:50
        $response = $this->registerBuyer(['first_name' => 'J']);

        $response->assertStatus(422)->assertJsonValidationErrorFor('first_name');
    }

    public function test_buy_006_2_char_first_name_accepted(): void
    {
        $response = $this->registerBuyer(['first_name' => 'Jo']);
        $response->assertStatus(200)->assertJsonPath('ok', true);
    }

    public function test_buy_006_50_char_first_name_accepted(): void
    {
        $longName = 'Maria Cristina Angelica Bernadette Josefina Leonor';
        $this->assertSame(50, strlen($longName));

        $response = $this->registerBuyer(['first_name' => $longName]);
        $response->assertStatus(200)->assertJsonPath('ok', true);
    }

    public function test_buy_006_51_char_first_name_rejected(): void
    {
        // The form request has max:50, so 51 chars is rejected.
        $longName = 'Maria Cristina Angelica Bernadette Josefina Leonora';
        $this->assertSame(51, strlen($longName));

        $response = $this->registerBuyer(['first_name' => $longName]);
        $response->assertStatus(422)->assertJsonValidationErrorFor('first_name');
    }

    // ── TC-BUY-007: Age at 18-year limit ────────────────────────────────────

    public function test_buy_007_born_10_08_2008_17_years_old_rejected(): void
    {
        // Today is 2026-10-08. Born 2008-10-09 = 17 years old (reject)
        $response = $this->registerBuyer(['birthday' => '2008-10-09']);

        $response->assertStatus(422)->assertJsonValidationErrorFor('birthday');
    }

    public function test_buy_007_born_10_07_2008_turns_18_accepted(): void
    {
        // Today is 2026-10-08. Born 2008-10-08 = exactly 18 years old (accept)
        $response = $this->registerBuyer(['birthday' => '2008-10-08']);
        $response->assertStatus(200)->assertJsonPath('ok', true);

        $buyer = Buyer::query()->latest('id')->firstOrFail();
        $this->assertSame(18, $buyer->age);
    }

    public function test_buy_007_born_10_06_2008_18_and_1_day_accepted(): void
    {
        // Today is 2026-10-08. Born 2008-10-07 = 18 years and 1 day old (accept)
        $response = $this->registerBuyer(['birthday' => '2008-10-07']);
        $response->assertStatus(200)->assertJsonPath('ok', true);

        $buyer = Buyer::query()->latest('id')->firstOrFail();
        $this->assertSame(18, $buyer->age);
    }

    // ── TC-BUY-008: DOB in the future ───────────────────────────────────────

    public function test_buy_008_dob_tomorrow_rejected(): void
    {
        $response = $this->registerBuyer(['birthday' => '2026-10-09']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('birthday');
    }

    // ── TC-BUY-009: Invalid email formats ───────────────────────────────────

    public function test_buy_009_email_without_at(): void
    {
        $response = $this->registerBuyer(['email' => 'juanmiguel.delacruzexample.com']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_buy_009_email_no_domain(): void
    {
        $response = $this->registerBuyer(['email' => 'juanmiguel.delacruz@']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_buy_009_email_no_name(): void
    {
        $response = $this->registerBuyer(['email' => '@example.com']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_buy_009_email_no_tld(): void
    {
        // Email must have a TLD (domain with dot)
        $response = $this->registerBuyer(['email' => 'juanmiguel.delacruz@example']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_buy_009_email_with_space(): void
    {
        // Quoted email with space is not accepted by the strict regex
        $response = $this->registerBuyer(['email' => '"juanmiguel delacruz"@example.com']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    // ── TC-BUY-010: Email already registered ────────────────────────────────

    public function test_buy_010_email_already_registered(): void
    {
        Buyer::create([
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'sex' => 'male',
            'email' => 'juanmiguel.delacruz@example.com',
            'password' => Hash::make('Mamimili@2026'),
            'contact_no' => '09181234567',
            'birthday' => '1998-05-14',
            'age' => 28,
            'address_mode' => 'manual',
            'province_name' => 'Test',
            'municipality_name' => 'Test',
            'barangay_name' => 'Test',
            'street' => 'Test St.',
            'upload_id_path' => 'ids/test.pdf',
            'email_verification_code' => null,
            'status' => 'unverified',
        ]);

        $response = $this->registerBuyer(['email' => 'juanmiguel.delacruz@example.com']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
        $this->assertSame(1, Buyer::count());
    }

    // ── TC-BUY-011: Email in capital letters ────────────────────────────────

    public function test_buy_011_email_uppercase_duplicate(): void
    {
        Buyer::create([
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'sex' => 'male',
            'email' => 'juanmiguel.delacruz@example.com',
            'password' => Hash::make('Mamimili@2026'),
            'contact_no' => '09181234567',
            'birthday' => '1998-05-14',
            'age' => 28,
            'address_mode' => 'manual',
            'province_name' => 'Test',
            'municipality_name' => 'Test',
            'barangay_name' => 'Test',
            'street' => 'Test St.',
            'upload_id_path' => 'ids/test.pdf',
            'status' => 'unverified',
        ]);

        $response = $this->registerBuyer([
            'email' => 'JUANMIGUEL.DELACRUZ@EXAMPLE.COM',
            'first_name' => 'DifferentFirst',
        ]);

        // Email is case-insensitive, so uppercase variant is treated as duplicate
        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    // ── TC-BUY-012: Email with leading/trailing spaces ──────────────────────

    public function test_buy_012_email_with_spaces_trimmed(): void
    {
        $payload = $this->basePayload();
        $response = $this->postJson(route('register.buyer.store'), array_merge($payload, [
            'email' => '  '.$payload['email'].'  ',
        ]));

        // Email is trimmed and lowercased by prepareForValidation,
        // so spaces are removed and account is created successfully
        $response->assertStatus(200)->assertJsonPath('ok', true);

        $buyer = Buyer::query()->latest('id')->firstOrFail();
        $this->assertSame(strtolower($payload['email']), $buyer->email);
    }

    // ── TC-BUY-013: Invalid mobile number formats ───────────────────────────

    public function test_buy_013_mobile_with_letters(): void
    {
        $response = $this->registerBuyer(['contact_no' => '0917ABC4567']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('contact_no');
    }

    public function test_buy_013_mobile_with_symbols_too_short(): void
    {
        $response = $this->registerBuyer(['contact_no' => '0917-123-456']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('contact_no');
    }

    public function test_buy_013_mobile_not_starting_with_09(): void
    {
        $response = $this->registerBuyer(['contact_no' => '12345678901']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('contact_no');
    }

    public function test_buy_013_landline_number(): void
    {
        $response = $this->registerBuyer(['contact_no' => '0281234567']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('contact_no');
    }

    // ── TC-BUY-014: Mobile number length at limits ──────────────────────────

    public function test_buy_014_10_digit_phone_rejected(): void
    {
        $response = $this->registerBuyer(['contact_no' => '0917123456']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('contact_no');
    }

    public function test_buy_014_11_digit_phone_accepted(): void
    {
        $response = $this->registerBuyer(['contact_no' => '09170000001']);
        $response->assertStatus(200)->assertJsonPath('ok', true);
    }

    public function test_buy_014_12_digit_phone_rejected(): void
    {
        $response = $this->registerBuyer(['contact_no' => '091712345678']);
        $response->assertStatus(422)->assertJsonValidationErrorFor('contact_no');
    }

    // ── TC-BUY-015: Mobile number already registered ─────────────────────────

    public function test_buy_015_mobile_already_registered(): void
    {
        Buyer::create([
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'sex' => 'male',
            'email' => 'existing01'.uniqid().'@example.com',
            'password' => Hash::make('Mamimili@2026'),
            'contact_no' => '09171234567',
            'birthday' => '1998-05-14',
            'age' => 28,
            'address_mode' => 'manual',
            'province_name' => 'Test',
            'municipality_name' => 'Test',
            'barangay_name' => 'Test',
            'street' => 'Test St.',
            'upload_id_path' => 'ids/test.pdf',
            'status' => 'unverified',
        ]);

        $response = $this->registerBuyer(['contact_no' => '09171234567']);

        // The form request now has a unique check on contact_no
        $response->assertStatus(422)->assertJsonValidationErrorFor('contact_no');
    }

    // ── TC-BUY-016: Mobile in +63 format ────────────────────────────────────

    public function test_buy_016_mobile_plus_63_format(): void
    {
        Buyer::create([
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'sex' => 'male',
            'email' => 'existing02'.uniqid().'@example.com',
            'password' => Hash::make('Mamimili@2026'),
            'contact_no' => '09171234567',
            'birthday' => '1998-05-14',
            'age' => 28,
            'address_mode' => 'manual',
            'province_name' => 'Test',
            'municipality_name' => 'Test',
            'barangay_name' => 'Test',
            'street' => 'Test St.',
            'upload_id_path' => 'ids/test.pdf',
            'status' => 'unverified',
        ]);

        $response = $this->registerBuyer(['contact_no' => '+639171234567']);

        // +639171234567 is normalized to 09171234567 which already exists
        // so it should be rejected with duplicate error, not format error
        $response->assertStatus(422)->assertJsonValidationErrorFor('contact_no');
        $this->assertStringContainsString('already in use', $response->json('errors.contact_no.0'));
    }

    // ── TC-BUY-017: Password length at limits ──────────────────────────────

    public function test_buy_017_7_char_password_rejected(): void
    {
        $response = $this->registerBuyer([
            'password' => 'Bili@26',
            'password_confirmation' => 'Bili@26',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrorFor('password');
    }

    public function test_buy_017_8_char_password_accepted(): void
    {
        $response = $this->registerBuyer([
            'password' => 'Bili@202',
            'password_confirmation' => 'Bili@202',
        ]);
        $response->assertStatus(200)->assertJsonPath('ok', true);
    }

    // ── TC-BUY-018: Password missing character type ────────────────────────

    public function test_buy_018_no_uppercase(): void
    {
        // The form request now requires complexity: uppercase, lowercase, digit, special
        $response = $this->registerBuyer([
            'password' => 'mamimili@2026',
            'password_confirmation' => 'mamimili@2026',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrorFor('password');
    }

    public function test_buy_018_no_lowercase(): void
    {
        $response = $this->registerBuyer([
            'password' => 'MAMIMILI@2026',
            'password_confirmation' => 'MAMIMILI@2026',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrorFor('password');
    }

    public function test_buy_018_no_number(): void
    {
        $response = $this->registerBuyer([
            'password' => 'Mamimili@abcd',
            'password_confirmation' => 'Mamimili@abcd',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrorFor('password');
    }

    public function test_buy_018_no_special_char(): void
    {
        $response = $this->registerBuyer([
            'password' => 'Mamimili2026',
            'password_confirmation' => 'Mamimili2026',
        ]);
        $response->assertStatus(422)->assertJsonValidationErrorFor('password');
    }

    // ── TC-BUY-019: Confirm password mismatch ────────────────────────────────

    public function test_buy_019_password_mismatch(): void
    {
        $response = $this->registerBuyer([
            'password' => 'Mamimili@2026',
            'password_confirmation' => 'Mamimili@2027',
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertArrayHasKey('password', $errors);
        $this->assertSame(0, Buyer::count());
    }

    // ── TC-BUY-020: Password hidden/show toggle ─────────────────────────────

    public function test_buy_020_password_hidden_by_default(): void
    {
        $html = $this->get(route('register.type'))->assertOk()->getContent();

        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('data-toggle-visibility="password"', $html);
    }

    // ── TC-BUY-021: Terms not accepted ──────────────────────────────────────

    public function test_buy_021_terms_not_accepted(): void
    {
        $response = $this->registerBuyer(['terms' => '']);
        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('terms')
            ->assertJsonPath('errors.terms.0', 'You must accept the Terms and Privacy Policy.');
        $this->assertSame(0, Buyer::count());
    }

    public function test_registration_rejects_missing_terms_field(): void
    {
        $payload = $this->basePayload();
        unset($payload['terms']);

        $this->postJson(route('register.buyer.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('terms');

        $this->assertSame(0, Buyer::count());
    }

    public function test_registration_rejects_unaccepted_terms_value(): void
    {
        $this->registerBuyer(['terms' => '0'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('terms');

        $this->assertSame(0, Buyer::count());
    }

    public function test_buyer_register_modal_renders_unchecked_terms_checkbox(): void
    {
        $html = $this->get(route('register.type'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<input\b[^>]*\bname="terms"[^>]*>/', $html, $matches));
        $input = $matches[0];
        $this->assertStringContainsString('type="checkbox"', $input);
        $this->assertDoesNotMatchRegularExpression('/\bchecked\b/', $input);
    }

    // ── TC-BUY-022: Double registration / rapid clicking ─────────────────────

    public function test_buy_007_turning_18_today_is_accepted_early_in_the_manila_morning(): void
    {
        // 01:00 on 2026-10-08 in Manila is still 2026-10-07 in UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-08 01:00:00', 'Asia/Manila'));

        $this->registerBuyer(['birthday' => '2008-10-08'])->assertStatus(200)->assertJsonPath('ok', true);
    }

    public function test_buy_007_008_birthday_messages(): void
    {
        $this->registerBuyer(['birthday' => '2008-10-09'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['birthday' => 'You must be at least 18 years old.']);

        $this->registerBuyer(['birthday' => '2026-10-09'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['birthday' => 'Please enter a valid date of birth. It cannot be in the future.']);
    }

    public function test_buy_022_repeat_submission_returns_the_first_registration_without_an_error(): void
    {
        Notification::fake();
        $payload = $this->basePayload();

        $first = $this->postJson(route('register.buyer.store'), $payload)->assertOk();
        $second = $this->postJson(route('register.buyer.store'), $payload)->assertOk();

        $this->assertSame($first->json('buyer_id'), $second->json('buyer_id'));
        $this->assertSame(1, Buyer::where('email', $payload['email'])->count());
        $this->assertSame(1, Notification::sent(Buyer::where('email', $payload['email'])->first(), BuyerVerifyOtp::class)->count());
    }

    public function test_buy_010_identical_resubmission_after_the_double_submit_window_is_blocked(): void
    {
        Notification::fake();
        $payload = $this->basePayload();

        $this->postJson(route('register.buyer.store'), $payload)->assertOk();

        Carbon::setTestNow(now()->addSeconds(11));

        $this->postJson(route('register.buyer.store'), array_merge($payload, [
            'upload_id' => UploadedFile::fake()->create('id2.pdf', 100, 'application/pdf'),
        ]))->assertStatus(422)->assertJsonValidationErrorFor('email');

        $this->assertSame(1, Buyer::where('email', $payload['email'])->count());
    }

    public function test_buy_022_same_email_with_a_different_password_is_not_a_duplicate(): void
    {
        Notification::fake();
        $payload = $this->basePayload();

        $this->postJson(route('register.buyer.store'), $payload)->assertOk();

        $this->postJson(route('register.buyer.store'), array_merge($payload, [
            'upload_id' => UploadedFile::fake()->create('id2.pdf', 100, 'application/pdf'),
            'password' => 'Different@2026',
            'password_confirmation' => 'Different@2026',
        ]))->assertStatus(422)->assertJsonValidationErrorFor('email');

        $this->assertSame(1, Buyer::where('email', $payload['email'])->count());
    }

    public function test_buy_022_multiple_rapid_submissions_single_account(): void
    {
        Notification::fake();

        $payload = $this->basePayload();

        $this->postJson(route('register.buyer.store'), $payload);
        $this->postJson(route('register.buyer.store'), $payload);
        $this->postJson(route('register.buyer.store'), $payload);

        $this->assertSame(1, Buyer::where('email', $payload['email'])->count());

        $buyer = Buyer::where('email', $payload['email'])->firstOrFail();
        Notification::assertSentTo($buyer, BuyerVerifyOtp::class);
        $this->assertSame(1, Notification::sent($buyer, BuyerVerifyOtp::class)->count());
    }

    // ── TC-BUY-023: Verify with correct OTP ─────────────────────────────────

    public function test_buy_023_verify_with_correct_otp(): void
    {
        [$buyer, $otp, $regResponse] = $this->registerAndCaptureOtp();

        $this->assertSame('unverified', $buyer->status);
        $this->assertNull($buyer->email_verified_at);

        $verifyResponse = $this->verifyOtp($buyer, $otp);
        $verifyResponse->assertOk()->assertJsonPath('ok', true);

        $buyer->refresh();
        $this->assertNotNull($buyer->email_verified_at);
        $this->assertNull($buyer->email_verification_code);
        $this->assertSame('pending', $buyer->status);
    }

    // ── TC-BUY-024: Verify with incorrect OTP ────────────────────────────────

    public function test_buy_024_verify_with_incorrect_otp(): void
    {
        [$buyer, $otp] = $this->registerAndCaptureOtp();
        $this->assertSame('unverified', $buyer->status);

        $wrongOtp = '000000';
        if ($otp === '000000') {
            $wrongOtp = '111111';
        }

        $response = $this->verifyOtp($buyer, $wrongOtp);
        $response->assertStatus(422)->assertJsonPath('ok', false);

        $buyer->refresh();
        $this->assertSame('unverified', $buyer->status);
        $this->assertNull($buyer->email_verified_at);
    }

    // ── TC-BUY-025: OTP in wrong format ─────────────────────────────────────

    public function test_buy_025_otp_5_digits_rejected(): void
    {
        [$buyer] = $this->registerAndCaptureOtp();

        $response = $this->verifyOtp($buyer, '48291');
        $response->assertStatus(422);
    }

    public function test_buy_025_otp_7_digits_rejected(): void
    {
        [$buyer] = $this->registerAndCaptureOtp();

        $response = $this->verifyOtp($buyer, '4829137');
        $response->assertStatus(422);
    }

    public function test_buy_025_otp_letters_rejected(): void
    {
        [$buyer] = $this->registerAndCaptureOtp();

        $response = $this->verifyOtp($buyer, '48A91B');
        $response->assertStatus(422);
    }

    public function test_buy_025_otp_blank_rejected(): void
    {
        [$buyer] = $this->registerAndCaptureOtp();

        $response = $this->verifyOtp($buyer, '');
        $response->assertStatus(422);
    }

    // ── TC-BUY-026: OTP expired ─────────────────────────────────────────────

    public function test_buy_026_otp_expired(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        [$buyer, $otp] = $this->registerAndCaptureOtp();
        $this->assertSame('unverified', $buyer->status);

        // The OTP lives 5 minutes; the workbook enters it at 5:01.
        Carbon::setTestNow('2026-10-08 12:05:01');

        $buyer->refresh();
        $this->assertTrue($buyer->isVerificationLinkExpired());

        $response = $this->verifyOtp($buyer, $otp);
        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('expired', true)
            ->assertJsonPath('message', 'This code has expired. Tap "Resend code" for a new one.');

        $buyer->refresh();
        $this->assertSame('unverified', $buyer->status);
        $this->assertNull($buyer->email_verified_at);
    }

    public function test_buy_023_otp_is_still_valid_at_4_minutes_59_seconds(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        [$buyer, $otp] = $this->registerAndCaptureOtp();

        Carbon::setTestNow('2026-10-08 12:04:59');

        $this->verifyOtp($buyer, $otp)->assertOk()->assertJsonPath('ok', true);
    }

    public function test_buy_025_wrong_format_otp_gets_the_six_digit_message(): void
    {
        [$buyer] = $this->registerAndCaptureOtp();

        foreach (['48291', '4829137', '48A91B', ''] as $otp) {
            $this->verifyOtp($buyer, $otp)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['otp' => 'Enter the 6-digit code from your email.']);
        }

        $this->assertSame('unverified', $buyer->fresh()->status);
    }

    // ── TC-BUY-027: Resend OTP ──────────────────────────────────────────────

    public function test_buy_027_resend_otp(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        Notification::fake();

        [$buyer, $firstOtp] = $this->registerAndCaptureOtp();
        $this->assertSame('unverified', $buyer->status);

        // Resend OTP
        $resendResponse = $this->postJson(SignedOtpLink::resendUrl('buyer', $buyer));
        $resendResponse->assertOk();

        // Capture the new OTP
        $secondOtp = null;
        $allSent = Notification::sent($buyer, BuyerVerifyOtp::class);
        $this->assertSame(2, $allSent->count());
        $secondOtp = $allSent->last()->code;

        // First OTP should fail (was it changed?)
        $buyer->refresh();
        $firstOtpResponse = $this->verifyOtp($buyer, $firstOtp);
        $firstOtpResponse->assertStatus(422);
        $this->assertSame('unverified', $buyer->fresh()->status);

        // Second OTP should succeed
        $secondOtpResponse = $this->verifyOtp($buyer, $secondOtp);
        $secondOtpResponse->assertOk()->assertJsonPath('ok', true);

        $buyer->refresh();
        $this->assertNotNull($buyer->email_verified_at);
        $this->assertSame('pending', $buyer->status);
    }

    // ── TC-BUY-028: Login before verifying email ─────────────────────────────

    public function test_buy_028_login_before_email_verification(): void
    {
        $buyer = Buyer::create([
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'sex' => 'male',
            'email' => 'unverified'.uniqid().'@example.com',
            'password' => Hash::make('Mamimili@2026'),
            'contact_no' => '09171234567',
            'birthday' => '1998-05-14',
            'age' => 28,
            'address_mode' => 'manual',
            'province_name' => 'Test',
            'municipality_name' => 'Test',
            'barangay_name' => 'Test',
            'street' => 'Test St.',
            'upload_id_path' => 'ids/test.pdf',
            'status' => 'unverified',
        ]);

        $response = $this->post(route('unified.login'), [
            'email' => $buyer->email,
            'password' => 'Mamimili@2026',
        ]);

        // The UnifiedLoginController tries seller guard first, then logistics, then buyer.
        // For a buyer with status 'unverified', the buyer guard will attempt
        // to authenticate (password matches), but then status !== 'approved' triggers
        // logout. The user should NOT be logged in.
        $this->assertFalse(Auth::guard('buyer')->check());
        $response->assertRedirect();
    }

    // ── TC-BUY-029: Login with verified account ──────────────────────────────

    public function test_buy_029_login_with_verified_approved_account(): void
    {
        $buyer = Buyer::create([
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'middle_initial' => 'M',
            'sex' => 'male',
            'email' => 'verified'.uniqid().'@example.com',
            'password' => Hash::make('Mamimili@2026'),
            'contact_no' => '09171234567',
            'birthday' => '1998-05-14',
            'age' => 28,
            'address_mode' => 'manual',
            'province_name' => 'Test',
            'municipality_name' => 'Test',
            'barangay_name' => 'Test',
            'street' => 'Test St.',
            'upload_id_path' => 'ids/test.pdf',
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $response = $this->post(route('unified.login'), [
            'email' => $buyer->email,
            'password' => 'Mamimili@2026',
        ]);

        $response->assertRedirect(route('buyer.home'));
        $this->assertTrue(Auth::guard('buyer')->check());
        $this->assertSame('Juan Miguel', Auth::guard('buyer')->user()->first_name);
    }

    // ── TC-BUY-030: Access control - other role pages ───────────────────────

    public function test_buy_030_seller_dashboard_denied(): void
    {
        $buyer = Buyer::create([
            'last_name' => 'Dela Cruz',
            'first_name' => 'Juan Miguel',
            'sex' => 'male',
            'email' => 'access'.uniqid().'@example.com',
            'password' => Hash::make('Mamimili@2026'),
            'contact_no' => '09171234567',
            'birthday' => '1998-05-14',
            'age' => 28,
            'address_mode' => 'manual',
            'province_name' => 'Test',
            'municipality_name' => 'Test',
            'barangay_name' => 'Test',
            'street' => 'Test St.',
            'upload_id_path' => 'ids/test.pdf',
            'email_verified_at' => now(),
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->actingAs($buyer, 'buyer');

        // Seller dashboard (protected by auth:seller middleware)
        // Returns 302 redirect to seller.login, which satisfies "403 or redirect"
        $response = $this->get(route('seller.dashboard'));
        $this->assertThat(
            $response->getStatusCode(),
            $this->logicalOr($this->equalTo(403), $this->equalTo(302))
        );

        // Admin dashboard (protected by auth:admin middleware)
        // Returns 302 redirect to admin.login, which satisfies "403 or redirect"
        $response = $this->get(route('admin.dashboard'));
        $this->assertThat(
            $response->getStatusCode(),
            $this->logicalOr($this->equalTo(403), $this->equalTo(302))
        );
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

                return $last ? $last->code : null;
            }
        };

        $this->app->instance(Dispatcher::class, $notifier);

        return $notifier;
    }

    public function test_buy_030_failed_otp_email_releases_the_email_for_a_clean_retry(): void
    {
        $notifier = $this->fakeNotifier();
        $notifier->fail = true;

        $payload = $this->basePayload();

        $this->postJson(route('register.buyer.store'), $payload)->assertStatus(503);

        // The half-finished attempt must not keep the email/mobile reserved.
        $this->assertDatabaseMissing('buyers', ['email' => $payload['email']]);

        // A plain retry (mail back up) now succeeds with the same details.
        $notifier->fail = false;

        $this->postJson(route('register.buyer.store'), $this->basePayload([
            'email' => $payload['email'],
            'contact_no' => $payload['contact_no'],
        ]))->assertStatus(200);

        $buyer = Buyer::where('email', $payload['email'])->sole();

        $this->assertSame('unverified', $buyer->status);
        $this->assertCount(1, $notifier->sent);
    }

    public function test_buy_031_a_verified_account_still_blocks_duplicate_registration(): void
    {
        $existing = $this->createUnverifiedBuyer(['status' => 'pending']);

        $this->registerBuyer(['email' => $existing->email])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');

        $this->assertSame(1, Buyer::where('email', $existing->email)->count());
    }

    public function test_buy_032_failed_resend_keeps_the_previous_code_valid(): void
    {
        $notifier = $this->fakeNotifier();

        $this->postJson(route('register.buyer.store'), $this->basePayload())->assertStatus(200);

        $buyer = Buyer::query()->latest('id')->firstOrFail();
        $originalOtp = $notifier->lastCode();

        $notifier->fail = true;
        $this->postJson(SignedOtpLink::resendUrl('buyer', $buyer))->assertStatus(503);
        $notifier->fail = false;

        // The code the user already received must still work.
        $this->verifyOtp($buyer, $originalOtp)->assertStatus(200);
    }

    public function test_buy_033_successful_resend_invalidates_the_previous_code(): void
    {
        $notifier = $this->fakeNotifier();

        $this->postJson(route('register.buyer.store'), $this->basePayload())->assertStatus(200);

        $buyer = Buyer::query()->latest('id')->firstOrFail();
        $oldOtp = $notifier->lastCode();

        $this->postJson(SignedOtpLink::resendUrl('buyer', $buyer))->assertStatus(200);
        $newOtp = $notifier->lastCode();

        $this->assertNotSame($oldOtp, $newOtp);

        // Only the newest code is accepted.
        $this->verifyOtp($buyer, $oldOtp)->assertStatus(422);
        $this->verifyOtp($buyer, $newOtp)->assertStatus(200);
    }
}
