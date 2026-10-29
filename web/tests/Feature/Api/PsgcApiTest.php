<?php

namespace Tests\Feature\Api;

use App\Models\Psgc\PsgcBarangay;
use App\Models\Psgc\PsgcCity;
use App\Models\Psgc\PsgcProvince;
use App\Models\Psgc\PsgcRegion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PsgcApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_psgc_endpoints_return_data(): void
    {
        $reg = PsgcRegion::create([
            'code' => '1300000000',
            'name' => 'National Capital Region (NCR)',
            'short_name' => 'NCR',
        ]);

        $city = PsgcCity::create([
            'code' => '1303900000',
            'region_code' => '1300000000',
            'province_code' => null, // NCR province-less
            'name' => 'City of Manila',
            'postal_code' => '1000',
        ]);

        $brgy = PsgcBarangay::create([
            'code' => '1303900001',
            'city_code' => '1303900000',
            'name' => 'Barangay 1',
        ]);

        $this->getJson(route('api.psgc.regions'))
            ->assertOk()
            ->assertJsonPath('data.0.code', '1300000000');

        $this->getJson(route('api.psgc.cities', ['region_code' => '1300000000']))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'City of Manila');

        $this->getJson(route('api.psgc.barangays', ['city_code' => '1303900000']))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Barangay 1');
    }
}
