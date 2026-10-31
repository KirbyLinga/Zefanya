<?php

namespace App\Notifications\Seller;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Notification;

/**
 * A parcel is being returned to the seller (retry limit reached). Its stock
 * has been restored.
 */
class ParcelReturned extends Notification
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
            'type' => 'seller_parcel_returned',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'tracking_number' => $this->sellerOrder->tracking_number,
            'message' => 'Order '.($this->sellerOrder->order?->reference ?? '')
                .' is being returned to you. The stock has been restored.',
        ];
    }
}
