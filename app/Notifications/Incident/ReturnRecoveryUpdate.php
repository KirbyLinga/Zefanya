<?php

namespace App\Notifications\Incident;

use App\Models\Logistics\ParcelReturn;
use Illuminate\Notifications\Notification;

/**
 * A return left its normal path: a failed collection, a cancellation, an Admin
 * closure, a Seller receipt dispute or a loss. Database only, like other order alerts.
 */
class ReturnRecoveryUpdate extends Notification
{
    public function __construct(
        public ParcelReturn $returnRequest,
        public string $event,
        public string $title,
        public string $message,
        public ?string $url = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'return_recovery_'.$this->event,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'parcel_return_id' => $this->returnRequest->id,
            'seller_order_id' => $this->returnRequest->seller_order_id,
            'tracking_number' => $this->returnRequest->sellerOrder?->tracking_number,
        ];
    }
}
