<?php

namespace App\Notifications\Seller;

use Illuminate\Notifications\Notification;

class PickupFailed extends Notification
{
    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Pickup Failed',
            'message' => 'Pickup attempt #'.$this->data['attempt'].' failed: '.$this->data['reason'],
            'order_id' => $this->sellerOrder->id,
            'parcel_id' => $this->sellerOrder->id,
            'type' => 'pickup_failed',
            'attempt' => $this->data['attempt'] ?? null,
            'reason' => $this->data['reason'] ?? null,
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
