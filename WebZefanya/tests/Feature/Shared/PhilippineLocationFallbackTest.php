<?php

namespace Tests\Feature\Shared;

use App\Services\Shared\PhilippineLocationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhilippineLocationFallbackTest extends TestCase
{
    public function test_a_failed_lookup_serves_the_last_saved_list(): void
    {
        Cache::forever('psgc:regions:fallback', [
            ['code' => '030000000', 'name' => 'Central Luzon'],
        ]);
        Http::fake(['*' => Http::response('unavailable', 503)]);

        $regions = app(PhilippineLocationService::class)->getRegions();

        $this->assertSame('Central Luzon', $regions[0]['name']);
    }

    public function test_a_successful_lookup_is_kept_as_the_fallback(): void
    {
        Http::fake([
            '*' => Http::response([
                ['code' => '010000000', 'name' => 'Ilocos Region'],
            ]),
        ]);

        $regions = app(PhilippineLocationService::class)->getRegions();

        $this->assertSame('Ilocos Region', $regions[0]['name']);
        $this->assertSame('Ilocos Region', Cache::get('psgc:regions:fallback')[0]['name']);

        Cache::forget('psgc:regions');
        Http::fake(['*' => Http::response('unavailable', 503)]);

        $this->assertSame('Ilocos Region', app(PhilippineLocationService::class)->getRegions()[0]['name']);
    }
}
