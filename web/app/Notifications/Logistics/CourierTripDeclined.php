<?php

namespace App\Notifications\Logistics;

use App\Models\Logistics\LinehaulTripDecline;
use Illuminate\Notifications\Notification;

class CourierTripDeclined extends Notification
{
    public function __construct(public LinehaulTripDecline $decline) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'courier_trip_declined', 'title' => 'Courier declined a trip',
            'message' => $this->decline->snapshot['courier_name'].' declined batch #'.$this->decline->manifest_id.': '.$this->decline->reason,
            'manifest_id' => $this->decline->manifest_id, 'reason' => $this->decline->reason,
            'url' => route('logistics.manifests.show', $this->decline->manifest_id)];
    }
}
