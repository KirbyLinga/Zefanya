<?php

namespace App\Notifications\Incident;

use App\Models\Logistics\ParcelReturn;
use Illuminate\Notifications\Notification;

class ReturnRecoveryUpdate extends Notification
{
    public function __construct(
        private readonly ParcelReturn $return,
        private readonly string $event,
        private readonly string $title,
        private readonly string $message,
        private readonly ?string $url = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'return_recovery_update',
            'seller_order_id' => $this->return->seller_order_id,
            'parcel_return_id' => $this->return->id,
            'event' => $this->event,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
