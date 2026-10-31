<?php

namespace App\Notifications\Rider;

use App\Models\Shared\SellerOrder;

/**
 * The rider's second failed pickup attempt cancelled the order.
 */
class PickupFailure extends RiderNotification
{
    public function __construct(
        public SellerOrder $sellerOrder,
        public int $attempt,
        public string $reason,
    ) {}

    public function dedupeKey(): string
    {
        return 'pickup-failure:'.$this->sellerOrder->id.':'.$this->attempt;
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        return [
            'type' => 'rider_pickup_failed',
            'title' => 'Pickup failed - order cancelled',
            'message' => $this->parcelReference($this->sellerOrder).' was cancelled after '
                .$this->attempt.' failed pickup attempts: '.$this->reason.'.',
            'url' => route('rider.pickups.index'),
            'seller_order_id' => $this->sellerOrder->id,
            'tracking_number' => $this->sellerOrder->tracking_number,
            'attempt' => $this->attempt,
            'reason' => $this->reason,
        ];
    }
}
