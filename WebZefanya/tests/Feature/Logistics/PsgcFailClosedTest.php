<?php

namespace Tests\Feature\Logistics;

use App\Services\Shared\PhilippineLocationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PsgcFailClosedTest extends TestCase
{
    use LazilyRefreshDatabase;

    private PhilippineLocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PhilippineLocationService;
    }

    /** @test API failure on regions throws exception (fail closed) */
    public function test_regions_api_failure_throws_exception(): void
    {
        Http::fake([
            'https://psgc.gitlab.io/api/regions/' => Http::response([], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSGC regions API returned status 500');

        $this->service->getRegions();
    }

    /** @test API failure on provinces throws exception (fail closed) */
    public function test_provinces_api_failure_throws_exception(): void
    {
        Http::fake([
            'https://psgc.gitlab.io/api/provinces/' => Http::response([], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSGC provinces API returned status 500');

        $this->service->getProvinces();
    }

    /** @test API failure on cities/municipalities throws exception (fail closed) */
    public function test_cities_municipalities_api_failure_throws_exception(): void
    {
        Http::fake([
            'https://psgc.gitlab.io/api/provinces/030540000/cities-municipalities/' => Http::response([], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSGC cities/municipalities API returned status 500');

        $this->service->getCitiesMunicipalities('030540000');
    }

    /** @test API failure on barangays throws exception (fail closed) */
    public function test_barangays_api_failure_throws_exception(): void
    {
        Http::fake([
            'https://psgc.gitlab.io/api/cities-municipalities/030540100/barangays/' => Http::response([], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSGC barangays API returned status 500');

        $this->service->getBarangays('030540100');
    }

    /** @test Empty cache with API failure throws (fail closed, no fallback to submitted names) */
    public function test_empty_cache_and_api_failure_throws_no_fallback(): void
    {
        Cache::forget('psgc:regions');
        Cache::forget('psgc:provinces:all');

        Http::fake([
            'https://psgc.gitlab.io/api/regions/' => Http::response([], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->getRegions();
    }

    /** @test Valid cached data is returned when API is down */
    public function test_valid_cached_data_returned_when_api_down(): void
    {
        // Prime the cache with valid data
        Cache::put('psgc:regions', [
            ['code' => '030000000', 'name' => 'Region III'],
        ], 3600);

        Cache::put('psgc:provinces:030000000', [
            ['code' => '030540000', 'name' => 'Pampanga'],
        ], 3600);

        Cache::put('psgc:cities:030540000:030000000', [
            ['code' => '030540100', 'name' => 'San Fernando'],
        ], 3600);

        Cache::put('psgc:barangays:030540100', [
            ['code' => '0305401001', 'name' => 'Barangay 1'],
        ], 3600);

        // Now simulate API failure
        Http::fake([
            'https://psgc.gitlab.io/api/regions/' => Http::response([], 500),
            'https://psgc.gitlab.io/api/provinces/030000000/' => Http::response([], 500),
            'https://psgc.gitlab.io/api/cities-municipalities/' => Http::response([], 500),
            'https://psgc.gitlab.io/api/cities-municipalities/030540100/barangays/' => Http::response([], 500),
        ]);

        // Should return cached data, not throw
        $regions = $this->service->getRegions();
        $this->assertSame('Region III', $regions[0]['name']);

        $provinces = $this->service->getProvinces('030000000');
        $this->assertSame('Pampanga', $provinces[0]['name']);

        $cities = $this->service->getCitiesMunicipalities('030540000', '030000000');
        $this->assertSame('San Fernando', $cities[0]['name']);

        $barangays = $this->service->getBarangays('030540100');
        $this->assertSame('Barangay 1', $barangays[0]['name']);
    }

    /** @test validateAndResolve rejects forged names when API is down */
    public function test_validate_and_resolve_rejects_forged_names_when_api_down(): void
    {
        Http::fake([
            'https://psgc.gitlab.io/api/regions/' => Http::response([], 500),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Location validation service is currently unavailable');

        $this->service->validateAndResolve([
            'region_code' => '030000000',
            'province_code' => '030540000',
            'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001',
            'region_name' => 'Fake Region',
            'province_name' => 'Fake Province',
            'municipality_name' => 'Fake City',
            'barangay_name' => 'Fake Barangay',
        ]);
    }

    /** @test validateAndResolve accepts valid cached codes when API is down */
    public function test_validate_and_resolve_accepts_valid_cached_codes_when_api_down(): void
    {
        // Prime cache
        Cache::put('psgc:regions', [['code' => '030000000', 'name' => 'Region III']], 3600);
        Cache::put('psgc:provinces:030000000', [['code' => '030540000', 'name' => 'Pampanga']], 3600);
        Cache::put('psgc:cities:030540000:030000000', [['code' => '030540100', 'name' => 'San Fernando']], 3600);
        Cache::put('psgc:barangays:030540100', [['code' => '0305401001', 'name' => 'Barangay 1']], 3600);

        // API down
        Http::fake([
            'https://psgc.gitlab.io/api/regions/' => Http::response([], 500),
            'https://psgc.gitlab.io/api/provinces/030000000/' => Http::response([], 500),
            'https://psgc.gitlab.io/api/provinces/030540000/cities-municipalities/' => Http::response([], 500),
            'https://psgc.gitlab.io/api/cities-municipalities/030540100/barangays/' => Http::response([], 500),
        ]);

        // Should succeed using cached data
        $result = $this->service->validateAndResolve([
            'region_code' => '030000000',
            'province_code' => '030540000',
            'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001',
        ]);

        $this->assertSame('Region III', $result['region_name']);
        $this->assertSame('Pampanga', $result['province_name']);
        $this->assertSame('San Fernando', $result['municipality_name']);
        $this->assertSame('Barangay 1', $result['barangay_name']);
    }

    /** @test validateAndResolve rejects invalid codes even with cached data */
    public function test_validate_and_resolve_rejects_invalid_codes_with_cached_data(): void
    {
        Cache::put('psgc:regions', [['code' => '030000000', 'name' => 'Region III']], 3600);
        Cache::put('psgc:provinces:030000000', [['code' => '030540000', 'name' => 'Pampanga']], 3600);
        Cache::put('psgc:cities:030540000:030000000', [['code' => '030540100', 'name' => 'San Fernando']], 3600);
        Cache::put('psgc:barangays:030540100', [['code' => '0305401001', 'name' => 'Barangay 1']], 3600);

        Http::fake([]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The selected barangay is invalid');

        $this->service->validateAndResolve([
            'region_code' => '030000000',
            'province_code' => '030540000',
            'city_municipality_code' => '030540100',
            'barangay_code' => 'INVALID_CODE',
        ]);
    }
}
