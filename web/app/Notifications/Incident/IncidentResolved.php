<?php

namespace App\Notifications\Incident;

use App\Models\Incidents;
use Illuminate\Notifications\Notification;

class IncidentResolved extends Notification
{
    public function __construct(
        public Incidents $incident,
        public string $resolution,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'incident_resolved',
            'incident_id' => $this->incident->id,
            'seller_order_id' => $this->incident->incident_seller_order_id,
            'resolution' => $this->resolution,
            'message' => 'Parcel #'.$this->incident->incident_seller_order_id.' incident resolved: '.$this->resolution.'.',
        ];
    }
}
