<?php

use App\Models\Buyer;
use App\Models\LogisticsProvider;
use App\Models\Rider;
use App\Models\Seller;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

echo 'Buyers with province codes: '.Buyer::whereNotNull('province_code')->count().PHP_EOL;
echo 'Buyers with full codes: '.Buyer::whereNotNull('province_code')
    ->whereNotNull('municipality_code')
    ->whereNotNull('barangay_code')->count().PHP_EOL;

// Get some sample buyers
$buyers = Buyer::whereNotNull('province_code')
    ->whereNotNull('municipality_code')
    ->whereNotNull('barangay_code')
    ->limit(3)
    ->get();

foreach ($buyers as $b) {
    echo "Buyer #{$b->id}: {$b->first_name} {$b->last_name} - Province: {$b->province_code} / {$b->municipality_code} / {$b->barangay_code}".PHP_EOL;
}

// Get sellers
$sellers = Seller::whereNotNull('province_code')
    ->whereNotNull('municipality_code')
    ->whereNotNull('barangay_code')
    ->limit(3)
    ->get();

foreach ($sellers as $s) {
    echo "Seller #{$s->id}: {$s->business_name} - Province: {$s->province_code}".PHP_EOL;
}

// Get logistics providers
$providers = LogisticsProvider::whereNotNull('province_code')->limit(3)->get();
foreach ($providers as $p) {
    echo "Provider #{$p->id}: {$p->business_name} - Province: {$p->province_code} verified: ".($p->province_verified ? 'yes' : 'no').PHP_EOL;
}

// Get riders
$riders = Rider::limit(3)->get();
foreach ($riders as $r) {
    echo "Rider #{$r->id}: provider={$r->provider_id} area={$r->area_id}".PHP_EOL;
}
