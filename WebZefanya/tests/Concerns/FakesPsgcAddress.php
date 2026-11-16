<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Puts one province > municipality > barangay chain into the PSGC cache so
 * Buyer address checks pass without calling the real API in tests.
 */
trait FakesPsgcAddress
{
    protected function fakePsgcAddress(
        string $provinceCode = '0300000000',
        string $province = 'Batangas',
        string $municipalityCode = '0306800000',
        string $municipality = 'Lipa City',
        string $barangayCode = '030680001',
        string $barangay = 'Barangay 1',
    ): void {
        $existing = Cache::get('psgc:provinces:all', []);

        Cache::put('psgc:provinces:all', [...$existing, ['code' => $provinceCode, 'name' => $province]], 3600);
        Cache::put('psgc:cities:'.$provinceCode.':none', [['code' => $municipalityCode, 'name' => $municipality]], 3600);
        Cache::put('psgc:barangays:'.$municipalityCode, [['code' => $barangayCode, 'name' => $barangay]], 3600);
    }
}
