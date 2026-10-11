<?php

namespace App\Policies;

use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;

/**
 * Authorization for LOGISTICS actions on seller_orders: a logistics rep may
 * only drive shipments handled by their own fleet's couriers.
 */
class SellerOrderFleetPolicy
{
    /**
     * May this provider advance/see the shipment?
     *  - If the order has a pickup courier (courier_id), the provider
     *    must own it.
     *  - If the order has a delivery courier only, the provider must own it.
     *  - If the order has no courier yet (pickup phase), any approved
     *    provider may advance it.
     */
    public function advance(LogisticsProvider $provider, SellerOrder $sellerOrder): bool
    {
        if ($sellerOrder->courier !== null) {
            return $sellerOrder->courier->logistics_provider_id === $provider->getKey();
        }

        if ($sellerOrder->deliveryCourier !== null) {
            return $sellerOrder->deliveryCourier->logistics_provider_id === $provider->getKey();
        }

        return true;
    }
}
