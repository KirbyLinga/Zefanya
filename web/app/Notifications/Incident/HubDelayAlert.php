<?php

namespace App\Notifications\Incident;

use App\Models\Shared\Incidents;
use App\Services\Logistics\HubDelayService;
use Illuminate\Notifications\Notification;

class HubDelayAlert extends Notification
{
    public function __construct(public Incidents $incident, private readonly string $audience) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $level = $this->incident->custody_snapshot['level'];
        $hub = $this->incident->custody_snapshot['hub_name'];

        return [
            'type' => 'hub_delay',
            'title' => 'Parcel delayed at a hub',
            'incident_id' => $this->incident->id,
            'seller_order_id' => $this->incident->incident_seller_order_id,
            'message' => $this->audience === 'buyer'
                ? 'Your parcel is delayed at a hub. Logistics is reviewing its next movement.'
                : 'Parcel #'.$this->incident->incident_seller_order_id.' is waiting at '.$hub.'. '.HubDelayService::LEVELS[$level].'.',
            'url' => match ($this->audience) {
                'logistics' => route('logistics.exceptions.show', $this->incident),
                'admin' => route('admin.incidents.detail', $this->incident),
                default => null,
            },
        ];
    }
}
