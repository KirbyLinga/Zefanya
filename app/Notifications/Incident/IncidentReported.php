<?php

namespace App\Notifications\Incident;

use App\Models\Incidents;
use Illuminate\Notifications\Notification;

class IncidentReported extends Notification
{
    public function __construct(public Incidents $incident) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'incident_reported',
            'title' => 'Parcel incident reported',
            'incident_id' => $this->incident->id,
            'seller_order_id' => $this->incident->incident_seller_order_id,
            'incident_type' => $this->incident->type,
            'message' => 'Parcel #'.$this->incident->incident_seller_order_id.' was reported '
                .strtoupper($this->incident->type).'. Parcel operations are paused pending Admin review.',
        ];
    }
}
