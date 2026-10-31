<?php

namespace App\Notifications\Buyer;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Notification;

/**
 * A delivery attempt failed. The parcel will either be rescheduled or, after
 * the retry limit, returned to the seller.
 */
class DeliveryFailed extends Notification
{
    public function __construct(
        public SellerOrder $sellerOrder,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'delivery_failed',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'tracking_number' => $this->sellerOrder->tracking_number,
            'reason' => $this->sellerOrder->delivery_failure_reason,
            'message' => 'We could not complete delivery of your parcel. Our team will try again or contact you.',
        ];
    }
}
