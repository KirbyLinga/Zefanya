<?php

namespace App\Notifications\Logistics;

use Illuminate\Notifications\Notification;

/** A dispatched transfer batch or handoff that is not received in time. */
class TransferOverdue extends Notification
{
    /** @param  array<string, int>  $ids */
    public function __construct(
        public string $title,
        public string $message,
        public string $url,
        public array $ids = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'transfer_overdue',
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
        ] + $this->ids;
    }
}
