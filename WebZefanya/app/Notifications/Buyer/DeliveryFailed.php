<?php

namespace App\Notifications\Buyer;

use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerOrderStatusService;
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
            'title' => $this->sellerOrder->status->label((int) $this->sellerOrder->delivery_attempts),
            'delivery_attempts' => (int) $this->sellerOrder->delivery_attempts,
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'tracking_number' => $this->sellerOrder->tracking_number,
            'reason' => $this->sellerOrder->delivery_failure_reason,
            'message' => (int) $this->sellerOrder->delivery_attempts < SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS
                ? 'Delivery Attempt Unsuccessful — Retry Pending. Logistics will arrange the next step. Please check your delivery address and contact details.'
                : 'Delivery Failed. The permitted delivery attempts are exhausted. Logistics will arrange return to the Seller.',
        ];
    }
}
