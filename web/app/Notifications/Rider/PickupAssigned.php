<?php

namespace App\Notifications\Rider;

use App\Models\SellerOrder;

/**
 * Logistics reserved a pickup task for this rider.
 */
class PickupAssigned extends RiderNotification
{
    public function __construct(
        public SellerOrder $sellerOrder,
        public int $assignmentId,
    ) {}

    public function dedupeKey(): string
    {
        return 'pickup-assigned:'.$this->assignmentId;
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        return [
            'type' => 'rider_pickup_assigned',
            'title' => 'New pickup assignment',
            'message' => $this->parcelReference($this->sellerOrder).' is ready for collection. Open your pickup list for the seller details.',
            'url' => route('rider.pickups.index'),
            'seller_order_id' => $this->sellerOrder->id,
            'tracking_number' => $this->sellerOrder->tracking_number,
        ];
    }
}
