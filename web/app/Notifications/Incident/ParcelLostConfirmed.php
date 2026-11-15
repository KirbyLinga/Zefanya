<?php

namespace App\Notifications\Incident;

use App\Models\Logistics\ParcelLossCase;
use Illuminate\Notifications\Notification;

class ParcelLostConfirmed extends Notification
{
    public function __construct(private readonly ParcelLossCase $case, public readonly string $audience) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'parcel_lost_confirmed',
            'seller_order_id' => $this->case->seller_order_id,
            'title' => 'Shipment issue',
            'message' => 'An admin confirmed a loss for parcel #'.$this->case->seller_order_id.'.',
        ];
    }
}
