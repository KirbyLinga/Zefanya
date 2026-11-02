<?php

namespace App\Notifications\Buyer;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Notification;

/** In-app alert only. The code itself stays on the order page, out of the notifications table. */
class DeliveryOtpCode extends Notification
{
    public function __construct(public SellerOrder $parcel, public string $code) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'delivery_code',
            'seller_order_id' => $this->parcel->id,
            'tracking_number' => $this->parcel->tracking_number,
            'message' => 'Your rider needs your delivery code. Open this order to see it.',
        ];
    }
}
