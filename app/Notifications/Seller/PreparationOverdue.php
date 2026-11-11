<?php

namespace App\Notifications\Seller;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Notification;

/**
 * The accepted order passed its preparation deadline. The order is not
 * cancelled and no stock or money moves; the Seller is asked to act now.
 */
class PreparationOverdue extends Notification
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
            'type' => 'seller_preparation_overdue',
            'seller_order_id' => $this->sellerOrder->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'due_at' => $this->sellerOrder->preparation_due_at?->toIso8601String(),
            'message' => 'Order '.($this->sellerOrder->order?->reference ?? '#'.$this->sellerOrder->id)
                .' is past its preparation deadline. Pack and mark it ready, or cancel it before pickup.',
        ];
    }
}
