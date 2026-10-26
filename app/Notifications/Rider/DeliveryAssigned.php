<?php

namespace App\Notifications\Rider;

use App\Models\SellerOrder;

/**
 * Logistics assigned a delivery (or a re-delivery after a failed attempt) to
 * this rider.
 */
class DeliveryAssigned extends RiderNotification
{
    public function __construct(
        public SellerOrder $sellerOrder,
        public int $assignmentId,
        public bool $rescheduled = false,
    ) {}

    public function dedupeKey(): string
    {
        return 'delivery-assigned:'.$this->assignmentId;
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        return [
            'type' => 'rider_delivery_assigned',
            'title' => $this->rescheduled ? 'Re-delivery assigned' : 'New delivery assignment',
            'message' => $this->rescheduled
                ? $this->parcelReference($this->sellerOrder).' was rescheduled to you. Open your delivery list for the next step.'
                : $this->parcelReference($this->sellerOrder).' is assigned to you for the final delivery leg.',
            'url' => route('rider.deliveries.index'),
            'seller_order_id' => $this->sellerOrder->id,
            'tracking_number' => $this->sellerOrder->tracking_number,
        ];
    }
}
