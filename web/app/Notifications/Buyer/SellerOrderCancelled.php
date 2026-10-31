<?php

namespace App\Notifications\Buyer;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Notification;

/**
 * A seller declined their portion of the order. Sibling seller parcels continue.
 */
class SellerOrderCancelled extends Notification
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
        $shop = $this->sellerOrder->seller?->business_name ?? 'A seller';

        return [
            'type' => 'seller_order_cancelled',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'seller_name' => $shop,
            'message' => "{$shop} cancelled their part of your order. Other sellers on this order are unaffected.",
        ];
    }
}
