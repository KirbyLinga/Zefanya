<?php

namespace App\Notifications\Logistics;

use Illuminate\Notifications\Notification;

class CourierSealDiscrepancy extends Notification
{
    public function __construct(public int $manifestId) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'courier_seal_discrepancy', 'title' => 'Courier seal discrepancy',
            'message' => 'Batch #'.$this->manifestId.' arrived with a different seal. Logistics recorded a note before receiving.',
            'manifest_id' => $this->manifestId,
            'url' => route('logistics.manifests.receive.index', $this->manifestId),
        ];
    }
}
