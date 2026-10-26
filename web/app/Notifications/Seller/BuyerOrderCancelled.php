<?php

namespace App\Notifications\Seller;

use App\Models\SellerOrder;
use Illuminate\Notifications\Notification;

/**
 * A buyer cancelled their order with this shop before the seller accepted it.
 */
class BuyerOrderCancelled extends Notification
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
        $reference = $this->sellerOrder->order?->reference ?? '';

        return [
            'type' => 'buyer_order_cancelled',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $reference,
            'message' => $reference !== ''
                ? "A buyer cancelled order {$reference}. Stock has been restored."
                : 'A buyer cancelled an order. Stock has been restored.',
        ];
    }
}
