<?php

namespace App\Notifications\Seller;

use App\Enums\OrderStatus;
use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A buyer cancelled their order with this shop before Ready for Pickup.
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
        $note = $this->sellerOrder->statusHistories()
            ->where('to_status', OrderStatus::Cancelled->value)->where('actor_type', 'buyer')->value('note');
        $reason = Str::after((string) $note, 'Buyer cancelled: ');
        $message = $reference !== ''
            ? "A buyer cancelled order {$reference}. Stock has been restored."
            : 'A buyer cancelled an order. Stock has been restored.';

        return [
            'type' => 'buyer_order_cancelled',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $reference,
            'reason' => $reason,
            'message' => $reason !== '' ? $message.' Reason: '.$reason : $message,
        ];
    }
}
