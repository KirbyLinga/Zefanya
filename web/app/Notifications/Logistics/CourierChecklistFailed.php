<?php

namespace App\Notifications\Logistics;

use Illuminate\Notifications\Notification;

class CourierChecklistFailed extends Notification
{
    public function __construct(public string $name, public array $failed, public string $note) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'courier_checklist_failed', 'title' => 'Courier pre-trip check failed',
            'message' => $this->name.': '.implode(', ', $this->failed).'. '.$this->note,
            'failed_items' => $this->failed, 'note' => $this->note];
    }
}
