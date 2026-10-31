<?php

namespace App\Notifications\Seller;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Notification;

/**
 * A delivery attempt failed for one of this shop's parcels.
 */
class DeliveryFailed extends Notification
{
    public function __construct(
        public SellerOrder $sellerOrder,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'seller_delivery_failed',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'tracking_number' => $this->sellerOrder->tracking_number,
            'reason' => $this->sellerOrder->delivery_failure_reason,
            'message' => 'A delivery attempt failed for order '.($this->sellerOrder->order?->reference ?? '')
                .'. It will be re-scheduled or returned to you.',
        ];
    }
}
