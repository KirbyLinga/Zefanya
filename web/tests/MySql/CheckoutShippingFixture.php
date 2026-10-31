<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Matching PSGC data for the existing BootstrapsCheckoutCarriers fixture, per worker process. */
final class CheckoutShippingFixture
{
    public static function primeCache(): void
    {
        Http::preventStrayRequests();
        Cache::put('psgc:cities:030540000:030000000', [
            ['code' => '030540100', 'name' => 'San Fernando'],
            ['code' => '030540200', 'name' => 'Angeles'],
        ], 3600);
        Cache::put('psgc:barangays:030540100', [
            ['code' => '0305401001', 'name' => 'First'],
            ['code' => '0305401002', 'name' => 'Second'],
        ], 3600);
    }
}
