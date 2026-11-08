<?php

namespace App\Notifications\Logistics;

use App\Models\Logistics\LinehaulTripDelay;
use Illuminate\Notifications\Notification;

class CourierTripDelayed extends Notification
{
    public function __construct(public string $name, public int $manifestId, public LinehaulTripDelay $delay) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'courier_trip_delayed', 'title' => 'Courier reported a delay',
            'message' => $this->name.' reported a delay on batch #'.$this->manifestId.'. ETA '.$this->delay->new_eta->format('Y-m-d H:i T').'. '.$this->delay->reason,
            'manifest_id' => $this->manifestId, 'reason' => $this->delay->reason, 'new_eta' => $this->delay->new_eta->toIso8601String(),
            'url' => route('logistics.manifests.show', $this->manifestId, false)];
    }
}
