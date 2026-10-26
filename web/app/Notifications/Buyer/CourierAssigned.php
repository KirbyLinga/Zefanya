<?php

namespace App\Notifications\Buyer;

use App\Models\SellerOrder;
use Illuminate\Notifications\Notification;

class CourierAssigned extends Notification
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
            'type' => 'courier_assigned',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'courier_name' => $deliveryCourier?->name,
            'courier_phone' => $deliveryCourier?->phone,
            'tracking_number' => $this->sellerOrder->tracking_number,
            'message' => 'A courier has been assigned to deliver your order. Tracking: '.($this->sellerOrder->tracking_number ?? 'pending'),
        ];
    }
}
