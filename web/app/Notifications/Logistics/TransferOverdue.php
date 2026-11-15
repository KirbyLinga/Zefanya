<?php

namespace App\Notifications\Logistics;

use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use Illuminate\Notifications\Notification;

class TransferOverdue extends Notification
{
    public function __construct(private readonly Manifest|ParcelHandoff $transfer) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $manifest = $this->transfer instanceof Manifest;
        $label = ($manifest ? 'Manifest #' : 'Handoff #').$this->transfer->getKey();

        return [
            'type' => 'transfer_overdue',
            'title' => 'Transfer overdue',
            'message' => $label.' is still in transit past its expected arrival time.',
            'url' => route($manifest ? 'logistics.manifests.show' : 'logistics.handoffs.show', $this->transfer),
        ];
    }
}
