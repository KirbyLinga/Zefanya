<?php

namespace App\Notifications\Seller;

use App\Models\SellerOrder;
use Illuminate\Notifications\Notification;

/**
 * Tells a seller that a new order landed for their shop. Fired once per
 * seller_order as soon as checkout places the order, so the seller sees it in
 * their notification bell / dashboard without polling.
 */
class NewOrderReceived extends Notification
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
        $buyerName = $this->sellerOrder->order?->buyer?->fullName();

        return [
            'type' => 'seller_new_order',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'buyer_name' => $buyerName,
            'tracking_number' => $this->sellerOrder->tracking_number,
            'amount_minor' => $this->sellerOrder->subtotal_minor,
            'message' => 'New order '.($this->sellerOrder->order?->reference ?? '')
                .' from '.($buyerName ?? 'a buyer').'. Review and accept it.',
        ];
    }
}
