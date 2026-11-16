<?php

namespace App\Policies;

use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LogisticsHubService;

/**
 * Authorization for LOGISTICS actions on seller_orders: a logistics rep may
 * only drive shipments handled by their own fleet's couriers, or — while still
 * unclaimed — shipments its validated coverage can actually collect.
 */
class SellerOrderFleetPolicy
{
    public function __construct(private readonly LogisticsHubService $hubs = new LogisticsHubService) {}

    public function remediateAddress(LogisticsProvider $provider, SellerOrder $parcel): bool
    {
        if ($parcel->currentCustodyProviderId() !== null) {
            return $parcel->currentCustodyProviderId() === (int) $provider->id;
        }

        // An unclaimed pickup never grants address-edit authority.
        return $parcel->courier !== null && (int) $parcel->courier->logistics_provider_id === (int) $provider->id;
    }

    /**
     * May this provider advance/see the shipment?
     *  - If the order has a pickup courier (courier_id), the provider
     *    must own it.
     *  - If the order has a delivery courier only, the provider must own it.
     *  - If the order has no courier yet (pickup phase), the provider must
     *    hold operational coverage for the seller's origin barangay.
     *    Missing origin codes fail closed.
     */
    public function advance(LogisticsProvider $provider, SellerOrder $sellerOrder): bool
    {
        if ($sellerOrder->currentCustodyProviderId() !== null) {
            return $sellerOrder->currentCustodyProviderId() === (int) $provider->id;
        }
        if ($sellerOrder->courier !== null) {
            return (int) $sellerOrder->courier->logistics_provider_id === (int) $provider->getKey();
        }

        if ($sellerOrder->deliveryCourier !== null) {
            return (int) $sellerOrder->deliveryCourier->logistics_provider_id === (int) $provider->getKey();
        }

        return $this->hubs->servesOrigin($provider, $sellerOrder);
    }
}
