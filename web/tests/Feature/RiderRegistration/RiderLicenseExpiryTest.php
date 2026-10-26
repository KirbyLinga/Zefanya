<?php

namespace Tests\Feature\RiderRegistration;

use App\Models\Logistics\LogisticsProvider;
use App\Support\RiderValidation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * License expiry is a future-facing date: today (Asia/Manila) and any later
 * date are valid, only an already-expired date is rejected. It must never
 * inherit the birthday rule (which caps the date in the past).
 */
class RiderLicenseExpiryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();

        // 00:30 in Manila is still the previous day in UTC: proves the app
        // clock (not the server's UTC date) decides what "today" means.
        Carbon::setTestNow(Carbon::parse('2026-10-20 00:30:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function provider(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
            'approved_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $expiry, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Pedro Jose',
            'last_name' => 'Garcia',
            'birthday' => '1995-03-22',
            'email' => 'license.expiry@example.com',
            'contact_no' => '09451239876',
            'password' => 'Rider@2026',
            'password_confirmation' => 'Rider@2026',
            'license_no' => 'N01-23-456789',
            'license_expiry' => $expiry,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'EXP1234',
            'logistics_provider_id' => $this->provider()->id,
            'terms' => true,
            'address_mode' => 'api',
            'province' => '030540000',
            'province_name' => 'Pampanga',
            'municipality' => '030540100',
            'municipality_name' => 'San Fernando',
            'barangay' => '0305401001',
            'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal Street',
            'drivers_license' => UploadedFile::fake()->createWithContent('license.pdf', "%PDF-1.4\nLicense"),
            'or_cr' => UploadedFile::fake()->createWithContent('or-cr.pdf', "%PDF-1.4\nRegistration"),
        ], $overrides);
    }

    public function test_an_expiry_today_is_accepted(): void
    {
        $this->postJson(route('register.rider.store'), $this->payload('2026-10-20'))
            ->assertCreated()
            ->assertJsonMissingValidationErrors(['license_expiry']);

        $this->assertDatabaseCount('riders', 1);
    }

    public function test_an_expiry_several_years_ahead_is_accepted(): void
    {
        $this->postJson(route('register.rider.store'), $this->payload('2036-10-20'))
            ->assertCreated()
            ->assertJsonMissingValidationErrors(['license_expiry']);

        $this->assertDatabaseCount('riders', 1);
    }

    public function test_an_expiry_yesterday_is_rejected(): void
    {
        $this->postJson(route('register.rider.store'), $this->payload('2026-10-19'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['license_expiry'])
            ->assertJsonPath('errors.license_expiry.0', 'The driving license is expired.');

        $this->assertDatabaseCount('riders', 0);
    }

    public function test_the_birthday_rule_is_not_applied_to_the_expiry(): void
    {
        // A future birthday is (correctly) invalid, while the same far-future
        // date is a valid licence expiry.
        $this->postJson(route('register.rider.store'), $this->payload('2036-10-20', [
            'birthday' => '2036-01-01',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birthday'])
            ->assertJsonMissingValidationErrors(['license_expiry']);

        $this->assertDatabaseCount('riders', 0);
    }

    public function test_the_register_page_bounds_the_field_without_a_maximum(): void
    {
        $this->withoutVite();
        $this->provider();

        $html = $this->get(route('register.type'))->assertOk()->getContent();
        $input = $this->expiryInput($html, 'riderLicenseExpiry');

        $this->assertStringContainsString('min="2026-10-20"', $input);
        $this->assertStringContainsString('data-today="2026-10-20"', $input);
        $this->assertStringNotContainsString('max=', $input);
    }

    public function test_the_logistics_add_rider_page_uses_the_same_bounds(): void
    {
        $this->withoutVite();

        $html = $this->actingAs($this->provider(), 'logistics')
            ->get(route('logistics.riders.create'))
            ->assertOk()
            ->getContent();

        $input = $this->expiryInput($html, 'rider_license_expiry');

        $this->assertStringContainsString('min="2026-10-20"', $input);
        $this->assertStringContainsString('data-today="2026-10-20"', $input);
        $this->assertStringNotContainsString('max=', $input);
    }

    public function test_the_shared_helper_uses_manila_today(): void
    {
        $this->assertFalse(RiderValidation::isExpiryValid('2026-10-19'));
        $this->assertTrue(RiderValidation::isExpiryValid('2026-10-20'));
        $this->assertTrue(RiderValidation::isExpiryValid('2036-01-01'));
    }

    private function expiryInput(string $html, string $id): string
    {
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="'.preg_quote($id, '/').'"[^>]*>/',
            $html,
            'The expiry input was not rendered.',
        );

        preg_match('/<input[^>]*id="'.preg_quote($id, '/').'"[^>]*>/', $html, $match);

        return $match[0];
    }
}
