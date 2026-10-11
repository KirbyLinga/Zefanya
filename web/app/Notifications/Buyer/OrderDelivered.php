<?php

namespace App\Notifications\Buyer;

use App\Models\SellerOrder;
use Illuminate\Notifications\Notification;

class OrderDelivered extends Notification
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
            'type' => 'delivered',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'tracking_number' => $this->sellerOrder->tracking_number,
            'message' => 'Your order has been delivered. Confirm receipt to complete.',
        ];
    }
}
