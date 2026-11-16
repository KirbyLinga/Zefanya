<?php

namespace Tests\Feature\Buyer;

use App\Support\BuyerAddressVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\FakesPsgcAddress;
use Tests\TestCase;

class BuyerAddressVerifierTest extends TestCase
{
    use FakesPsgcAddress;

    /** @return array<string, string> */
    private function address(array $overrides = []): array
    {
        return array_merge([
            'address_mode' => 'api',
            'province' => '0300000000',
            'province_name' => 'Wrong Province',
            'municipality' => '0306800000',
            'municipality_name' => 'Wrong City',
            'barangay' => '030680001',
            'barangay_name' => 'Wrong Barangay',
            'street' => '1 Rizal St',
        ], $overrides);
    }

    private function verify(array $overrides = []): array
    {
        return app(BuyerAddressVerifier::class)->verify($this->address($overrides));
    }

    public function test_a_matching_chain_passes_and_names_become_the_official_ones(): void
    {
        $this->fakePsgcAddress();

        $result = $this->verify();

        $this->assertSame('Batangas', $result['province_name']);
        $this->assertSame('Lipa City', $result['municipality_name']);
        $this->assertSame('Barangay 1', $result['barangay_name']);
        $this->assertSame('1 Rizal St', $result['street']);
    }

    public function test_a_municipality_from_another_province_is_refused(): void
    {
        $this->fakePsgcAddress();

        $this->expectException(ValidationException::class);
        $this->verify(['municipality' => '0399999000']);
    }

    public function test_a_barangay_from_another_municipality_is_refused(): void
    {
        $this->fakePsgcAddress();

        try {
            $this->verify(['barangay' => '999999999']);
            $this->fail('A barangay outside the municipality must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('barangay', $e->errors());
        }
    }

    public function test_an_unknown_province_is_refused(): void
    {
        $this->fakePsgcAddress();

        try {
            $this->verify(['province' => '1111111111']);
            $this->fail('An unknown province must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('province', $e->errors());
        }
    }

    public function test_a_typed_in_manual_address_is_refused_so_every_address_carries_codes(): void
    {
        $data = ['address_mode' => 'manual', 'municipality_name' => 'Anywhere', 'street' => '1 Test St'];

        try {
            app(BuyerAddressVerifier::class)->verify($data);
            $this->fail('A manual address must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('address_mode', $e->errors());
        }
    }

    public function test_an_outage_serves_the_last_saved_lists_so_valid_addresses_still_pass(): void
    {
        $this->fakePsgcAddress();
        // Fresh lists expired; only the saved fallbacks remain.
        foreach (['psgc:provinces:all', 'psgc:cities:0300000000:none', 'psgc:barangays:0306800000'] as $key) {
            Cache::forever($key.':fallback', Cache::pull($key));
        }
        Http::fake(['*' => Http::response('unavailable', 503)]);

        $this->assertSame('Lipa City', $this->verify()['municipality_name']);
    }

    public function test_an_outage_with_nothing_saved_refuses_with_a_try_again_message(): void
    {
        Http::fake(['*' => Http::response('unavailable', 503)]);

        try {
            $this->verify();
            $this->fail('An address that cannot be checked must not be accepted.');
        } catch (ValidationException $e) {
            $this->assertSame([BuyerAddressVerifier::UNAVAILABLE_MESSAGE], $e->errors()['address']);
        }
    }

    public function test_metro_manila_uses_its_region_level_province(): void
    {
        Cache::put('psgc:cities:130000000:none', [['code' => '137404000', 'name' => 'Quezon City']], 3600);
        Cache::put('psgc:barangays:137404000', [['code' => '137404001', 'name' => 'Alicia']], 3600);

        $result = $this->verify(['province' => '130000000', 'municipality' => '137404000', 'barangay' => '137404001']);

        $this->assertSame('Metro Manila', $result['province_name']);
        $this->assertSame('Quezon City', $result['municipality_name']);
    }
}
