<?php

namespace Tests\Support;

use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Order;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\ProviderHandoffSchemaService;

/**
 * Phase 5.3–5.5 fixtures: two operational providers in different provinces,
 * an approved directed link, and a parcel whose buyer is in the destination
 * province.
 */
trait BootstrapsCrossProviderHandoffs
{
    protected function handoffProvider(string $name, string $provinceCode = '030540000', string $provinceName = 'Pampanga'): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'business_name' => $name,
            'province_code' => $provinceCode,
            'province_name' => $provinceName,
            'municipality_code' => $provinceCode.'100',
            'municipality_name' => $provinceName === 'Pampanga' ? 'San Fernando' : 'Quezon City',
            'barangay_code' => $provinceCode.'1001',
            'barangay_name' => 'Santo Rosario',
            'province_verified_at' => now(),
        ]);
    }

    /**
     * @return array{provider: LogisticsProvider, province: LogisticsHub, station: LogisticsHub, area: Area}
     */
    protected function operationalHandoffProvider(
        string $name,
        string $provinceCode,
        string $provinceName,
        string $municipalityCode,
        string $municipalityName,
        string $barangayCode,
        string $barangayName,
        string $regionCode = '030000000',
        string $regionName = 'Region III',
    ): array {
        $provider = $this->handoffProvider($name, $provinceCode, $provinceName);

        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => $name.' Province Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => $regionCode,
            'region_name' => $regionName,
            'province_code' => $provinceCode,
            'province_name' => $provinceName,
            'municipality' => $municipalityName,
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);

        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => $name.' '.$barangayName.' Station',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $regionCode,
            'region_name' => $regionName,
            'province_code' => $provinceCode,
            'province_name' => $provinceName,
            'city_municipality_code' => $municipalityCode,
            'municipality' => $municipalityName,
            'barangay_code' => $barangayCode,
            'barangay_name' => $barangayName,
            'street' => '1 Coverage Street',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);

        $area = Area::query()->create([
            'logistics_provider_id' => $provider->id,
            'station_hub_id' => $station->id,
            'name' => $name.' coverage',
            'city_municipality_code' => $municipalityCode,
            'municipality' => $municipalityName,
            'barangay_code' => $barangayCode,
            'barangay_name' => $barangayName,
            'coverage_validated_at' => now(),
            'is_active' => true,
        ]);

        return compact('provider', 'province', 'station', 'area');
    }

    protected function activeHandoffLink(
        LogisticsProvider $origin,
        LogisticsProvider $destination,
        Admin $admin,
        int $priority = 0,
    ): LogisticsProviderLink {
        $service = app(ProviderHandoffSchemaService::class);
        $link = $service->requestLink($origin, $destination, $priority);
        $service->consent($link, $destination);

        return $service->approve($link, $admin, $priority);
    }

    protected function sellerIn(
        string $provinceCode,
        string $provinceName,
        string $municipalityCode,
        string $municipalityName,
        string $barangayCode,
        string $barangayName,
    ): Seller {
        return Seller::factory()->create([
            'province_code' => $provinceCode,
            'province_name' => $provinceName,
            'municipality_code' => $municipalityCode,
            'municipality_name' => $municipalityName,
            'barangay_code' => $barangayCode,
            'barangay_name' => $barangayName,
        ]);
    }

    protected function buyerIn(
        string $provinceCode,
        string $provinceName,
        string $municipalityCode,
        string $municipalityName,
        string $barangayCode,
        string $barangayName,
    ): Buyer {
        return Buyer::factory()->create([
            'province_code' => $provinceCode,
            'province_name' => $provinceName,
            'municipality_code' => $municipalityCode,
            'municipality_name' => $municipalityName,
            'barangay_code' => $barangayCode,
            'barangay_name' => $barangayName,
        ]);
    }

    protected function handoffParcel(
        Seller $seller,
        Buyer $buyer,
        LogisticsProvider $carrier,
        string $trackingNumber = 'TRK-HANDOFF-0001',
        OrderStatus $status = OrderStatus::AtSortingCenter,
    ): SellerOrder {
        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'shipping_address' => [
                'name' => $buyer->fullName(),
                'phone' => $buyer->contact_no,
                'province' => $buyer->province_name,
                'province_code' => $buyer->province_code,
                'municipality' => $buyer->municipality_name,
                'municipality_code' => $buyer->municipality_code,
                'barangay' => $buyer->barangay_name,
                'barangay_code' => $buyer->barangay_code,
                'street' => 'Test Street',
                'house_number' => '1',
                'full' => '1 Test Street',
            ],
        ]);

        $parcel = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'carrier_provider_id' => $carrier->id,
            'tracking_number' => $trackingNumber,
        ]);

        $parcel->forceFill(['status' => $status->value])->save();

        return $parcel->fresh(['order.buyer', 'seller']);
    }
}
