<?php

namespace App\Notifications\Buyer;

use App\Models\SellerOrder;
use Illuminate\Notifications\Notification;

class OrderOutForDelivery extends Notification
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
        $deliveryCourier = $this->sellerOrder->deliveryCourier;

        return [
            'type' => 'out_for_delivery',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'courier_name' => $deliveryCourier?->name,
            'tracking_number' => $this->sellerOrder->tracking_number,
            'message' => 'Your order is out for delivery.',
        ];
    }
}
