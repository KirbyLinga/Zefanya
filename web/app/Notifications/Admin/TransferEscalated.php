<?php

namespace App\Notifications\Admin;

use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use Illuminate\Notifications\Notification;

class TransferEscalated extends Notification
{
    public function __construct(private readonly Manifest|ParcelHandoff $transfer) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $label = ($this->transfer instanceof Manifest ? 'Manifest #' : 'Handoff #').$this->transfer->getKey();

        return [
            'type' => 'transfer_escalated',
            'title' => 'Overdue transfer needs review',
            'message' => $label.' is still in transit and needs investigation.',
            'url' => route('admin.transport.index'),
        ];
    }
}
