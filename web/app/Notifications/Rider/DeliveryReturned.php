<?php

namespace App\Notifications\Rider;

use App\Models\Shared\SellerOrder;

/**
 * A failed delivery was closed by Logistics returning the parcel to the
 * seller. The task is cancelled for the rider and moves to history.
 */
class DeliveryReturned extends RiderNotification
{
    public function __construct(
        public SellerOrder $sellerOrder,
        public int $attempt,
        public bool $receiptPending = false,
    ) {}

    public function dedupeKey(): string
    {
        return ($this->receiptPending ? 'physical-return-requested:' : 'delivery-returned:').$this->sellerOrder->id.':'.$this->attempt;
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        return [
            'type' => $this->receiptPending ? 'rider_return_requested' : 'rider_delivery_returned',
            'title' => $this->receiptPending ? 'Physical return requested' : 'Delivery returned to seller',
            'message' => $this->receiptPending ? $this->parcelReference($this->sellerOrder).' is closed for delivery and awaits verified return to the Seller.' : $this->parcelReference($this->sellerOrder).' was returned to the seller and is no longer in your active delivery list.',
            'url' => route('rider.history.index'),
            'seller_order_id' => $this->sellerOrder->id,
            'tracking_number' => $this->sellerOrder->tracking_number,
        ];
    }
}
