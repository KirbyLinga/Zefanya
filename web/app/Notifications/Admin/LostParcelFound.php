<?php

namespace App\Notifications\Admin;

use App\Models\Logistics\ParcelLossCase;
use Illuminate\Notifications\Notification;

class LostParcelFound extends Notification
{
    public function __construct(private readonly ParcelLossCase $case) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'lost_parcel_found',
            'title' => 'Lost parcel found',
            'message' => 'Parcel #'.$this->case->seller_order_id.' was located at a verified hub.',
            'url' => route('admin.lost-parcels.index'),
        ];
    }
}
