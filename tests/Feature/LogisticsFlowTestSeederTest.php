<?php

namespace Tests\Feature;

use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Services\LogisticsHubService;
use Database\Seeders\LogisticsFlowTestSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LogisticsFlowTestSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dry_run_plans_same_province_coverage_without_writing_and_apply_is_idempotent(): void
    {
        Http::preventStrayRequests();
        Cache::put('psgc:cities:043400000:040000000', [
            ['code' => '043411000', 'name' => 'Los Banos'],
        ], 3600);
        Cache::put('psgc:barangays:043411000', [
            ['code' => '043411006', 'name' => 'Bayog'],
            ['code' => '043411008', 'name' => 'Maahas'],
        ], 3600);

        $provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'business_name' => 'Laguna Flow Co',
            'province_code' => '043400000',
            'province_name' => 'Laguna',
            'province_verified_at' => now(),
        ]);

        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => 'Laguna Province Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '040000000',
            'province_code' => '043400000',
            'province_name' => 'Laguna',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);

        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => 'Los Banos Station',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => '040000000',
            'province_code' => '043400000',
            'province_name' => 'Laguna',
            'city_municipality_code' => '043411000',
            'municipality' => 'Los Banos',
            'barangay_code' => '043411008',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);

        (new LogisticsHubService)->saveCoverage($provider->id, [
            'name' => 'Maahas',
            'station_hub_id' => $station->id,
            'city_municipality_code' => '043411000',
            'barangay_code' => '043411008',
            'is_active' => true,
        ]);

        $seller = Seller::factory()->create([
            'status' => 'approved',
            'province_code' => '043400000',
            'province_name' => 'Laguna',
            'municipality_code' => '043411000',
            'municipality_name' => 'Los Banos',
            'barangay_code' => '043411006',
            'barangay_name' => 'Bayog',
        ]);

        Buyer::factory()->create([
            'status' => 'approved',
            'province_code' => '043400000',
            'province_name' => 'Laguna',
            'municipality_code' => '043411000',
            'municipality_name' => 'Los Banos',
            'barangay_code' => '043411006',
            'barangay_name' => 'Bayog',
        ]);

        $rider = Rider::factory()->create([
            'logistics_provider_id' => $provider->id,
            'station_hub_id' => $station->id,
            'status' => Rider::STATUS_APPROVED,
            'email_verified_at' => now(),
        ]);

        Courier::factory()->create([
            'logistics_provider_id' => $provider->id,
            'rider_id' => $rider->id,
            'area_id' => null,
            'is_available' => true,
        ]);

        putenv('LOGISTICS_FLOW_SEED_APPLY=0');
        $seeder = new LogisticsFlowTestSeeder;
        $plan = $seeder->buildPlan();

        $this->assertSame(1, $plan['counts']['verified_providers']);
        $this->assertTrue($plan['scenarios']['same_province_delivery']['possible_after_seed']);
        $this->assertFalse($plan['scenarios']['cross_province_delivery']['possible_after_seed']);
        $this->assertContains(
            'create_coverage',
            array_column($plan['actions'], 'type'),
        );
        $this->assertContains(
            'enable_shipping',
            array_column($plan['actions'], 'type'),
        );

        // Dry-run must not enable shipping.
        $this->assertDatabaseMissing('seller_shipping_providers', [
            'seller_id' => $seller->id,
            'logistics_provider_id' => $provider->id,
        ]);

        putenv('LOGISTICS_FLOW_SEED_APPLY=1');
        $seeder->applyPlan($plan);

        $this->assertDatabaseHas('seller_shipping_providers', [
            'seller_id' => $seller->id,
            'logistics_provider_id' => $provider->id,
            'is_enabled' => 1,
        ]);
        $this->assertNotNull(
            app(LogisticsHubService::class)->areaForBarangay($provider->id, '043411006'),
        );
        $this->assertNotNull($rider->fresh()->courier?->area_id);

        // Idempotent re-apply.
        $second = $seeder->buildPlan();
        $seeder->applyPlan($second);
        $this->assertSame(
            1,
            SellerShippingProvider::query()->where('seller_id', $seller->id)->count(),
        );

        putenv('LOGISTICS_FLOW_SEED_APPLY');
    }
}
