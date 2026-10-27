<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Database query test
require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Simple count
echo 'Total buyers: '.DB::table('buyers')->count().PHP_EOL;

// Buyers with province codes
$count = DB::table('buyers')->whereNotNull('province_code')->count();
echo "Buyers with province_code: $count".PHP_EOL;

// Buyers with full codes
$count2 = DB::table('buyers')
    ->whereNotNull('province_code')
    ->whereNotNull('municipality_code')
    ->whereNotNull('barangay_code')
    ->count();
echo "Buyers with full codes: $count2".PHP_EOL;

// Sample buyer
$buyer = DB::table('buyers')
    ->whereNotNull('province_code')
    ->whereNotNull('municipality_code')
    ->whereNotNull('barangay_code')
    ->first();
echo 'Sample buyer: '.json_encode($buyer).PHP_EOL;

// Sellers
$sellerCount = DB::table('sellers')->count();
echo "Total sellers: $sellerCount".PHP_EOL;
$sellerWithCodes = DB::table('sellers')
    ->whereNotNull('province_code')
    ->whereNotNull('municipality_code')
    ->whereNotNull('barangay_code')
    ->count();
echo "Sellers with full codes: $sellerWithCodes".PHP_EOL;

// Sample seller
$seller = DB::table('sellers')
    ->whereNotNull('province_code')
    ->whereNotNull('municipality_code')
    ->whereNotNull('barangay_code')
    ->first();
echo 'Sample seller: '.json_encode($seller).PHP_EOL;

// Logistics providers
$providerCount = DB::table('logistics_providers')->count();
echo "Total providers: $providerCount".PHP_EOL;
$providerWithVerified = DB::table('logistics_providers')
    ->whereNotNull('province_code')
    ->where('province_verified', 1)
    ->count();
echo "Providers with verified province: $providerWithVerified".PHP_EOL;

// Sample provider
$provider = DB::table('logistics_providers')
    ->whereNotNull('province_code')
    ->where('province_verified', 1)
    ->first();
echo 'Sample provider: '.json_encode($provider).PHP_EOL;

// Riders
$riderCount = DB::table('riders')->count();
echo "Total riders: $riderCount".PHP_EOL;

// Seller shipping providers
$sspCount = DB::table('seller_shipping_providers')->count();
echo "Seller shipping providers: $sspCount".PHP_EOL;
