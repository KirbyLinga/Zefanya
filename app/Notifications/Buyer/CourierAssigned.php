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
        return [
            'type' => 'courier_assigned',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'courier_name' => $this->sellerOrder->courier?->name,
            'courier_phone' => $this->sellerOrder->courier?->phone,
            'tracking_number' => $this->sellerOrder->tracking_number,
            'message' => 'A courier has been assigned to your order. Tracking: '.($this->sellerOrder->tracking_number ?? 'pending'),
        ];
    }
}
