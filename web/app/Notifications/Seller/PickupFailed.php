<?php

namespace App\Notifications\Seller;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Notification;

class PickupFailed extends Notification
{
    public function __construct(
        public SellerOrder $sellerOrder,
        public int $attempt,
        public string $reason,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Pickup Failed',
            'message' => 'Pickup attempt #'.$this->attempt.' failed: '.$this->reason,
            'order_id' => $this->sellerOrder->id,
            'parcel_id' => $this->sellerOrder->id,
            'type' => 'pickup_failed',
            'attempt' => $this->attempt,
            'reason' => $this->reason,
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
