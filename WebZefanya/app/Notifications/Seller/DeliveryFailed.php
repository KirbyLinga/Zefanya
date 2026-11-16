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
            'title' => $this->sellerOrder->status->label((int) $this->sellerOrder->delivery_attempts),
            'delivery_attempts' => (int) $this->sellerOrder->delivery_attempts,
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'tracking_number' => $this->sellerOrder->tracking_number,
            'reason' => $this->sellerOrder->delivery_failure_reason,
            'message' => $this->sellerOrder->status->label((int) $this->sellerOrder->delivery_attempts)
                .' for order '.($this->sellerOrder->order?->reference ?? '')
                .'. Logistics will arrange the next step under the existing delivery policy.',
        ];
    }
}
