<?php

namespace Tests\Support;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Services\Logistics\LogisticsHubService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Same-province carrier fixtures for checkout store tests (Phase 3.2).
 *
 * Cross-province provider links are intentionally not bootstrapped — that
 * dependency belongs to a later handoff phase.
 */
trait BootstrapsCheckoutCarriers
{
    protected LogisticsProvider $checkoutCarrier;

    /**
     * Put buyer + sellers on the same province/barangay, create one operational
     * carrier covering that barangay, and enable it for every seller.
     *
     * @param  iterable<int, Seller>  $sellers
     */
    protected function bootstrapCheckoutCarriers(Buyer $buyer, iterable $sellers, string $barangay = '0305401001'): LogisticsProvider
    {
        Http::preventStrayRequests();

        // Factory weights are random, so tests about money keep a fixed fee here.
        // CheckoutShippingTest covers pricing by weight and sets its own rates.
        config([
            'buyer.shipping_rates.same_municipality.additional_kg_minor' => 0,
            'buyer.shipping_rates.same_province.additional_kg_minor' => 0,
        ]);

        Cache::put('psgc:cities:030540000:030000000', [
            ['code' => '030540100', 'name' => 'San Fernando'],
            ['code' => '030540200', 'name' => 'Angeles'],
        ], 3600);
        Cache::put('psgc:barangays:030540100', [
            ['code' => '0305401001', 'name' => 'First'],
            ['code' => '0305401002', 'name' => 'Second'],
        ], 3600);

        $address = [
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'municipality_code' => '030540100',
            'municipality_name' => 'San Fernando',
            'barangay_code' => $barangay,
            'barangay_name' => $barangay === '0305401001' ? 'First' : 'Second',
        ];

        $buyer->forceFill($address)->save();

        foreach ($sellers as $seller) {
            $seller->forceFill($address)->save();
        }

        $this->checkoutCarrier = $this->makeOperationalCheckoutCarrier('Checkout Carrier', $barangay);

        foreach ($sellers as $seller) {
            SellerShippingProvider::query()->updateOrCreate(
                [
                    'seller_id' => $seller->id,
                    'logistics_provider_id' => $this->checkoutCarrier->id,
                ],
                ['is_enabled' => true],
            );
        }

        return $this->checkoutCarrier;
    }

    protected function makeOperationalCheckoutCarrier(string $businessName, string $barangay): LogisticsProvider
    {
        $provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'business_name' => $businessName,
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'province_verified_at' => now(),
        ]);

        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => $businessName.' Province Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);

        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => $businessName.' Station',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => '030000000',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'city_municipality_code' => '030540100',
            'municipality' => 'San Fernando',
            'barangay_code' => 'physical',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);

        (new LogisticsHubService)->saveCoverage($provider->id, [
            'name' => 'Coverage '.$barangay,
            'station_hub_id' => $station->id,
            'city_municipality_code' => '030540100',
            'barangay_code' => $barangay,
            'is_active' => true,
        ]);

        return $provider->fresh();
    }

    /**
     * @param  iterable<int, Seller>  $sellers
     * @return array{carriers: array<int, int>}
     */
    protected function checkoutCarrierPayload(iterable $sellers, ?LogisticsProvider $provider = null): array
    {
        $provider ??= $this->checkoutCarrier;
        $carriers = [];

        foreach ($sellers as $seller) {
            $carriers[$seller->id] = $provider->id;
        }

        return ['carriers' => $carriers];
    }
}
