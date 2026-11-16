<?php

namespace App\Notifications\Seller;

use App\Models\Shared\OrderAddressReview;
use Illuminate\Notifications\Notification;

/** Tells the Seller an order is held for an address check, and when it is released. */
class AddressCheckHold extends Notification
{
    /** @param  'held'|'confirmed'|'not_confirmed'  $stage */
    public function __construct(public OrderAddressReview $review, public string $stage) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $review = $this->review;
        $reference = $review->sellerOrder?->order?->reference ?? 'shipment #'.$review->seller_order_id;

        [$title, $message] = match ($this->stage) {
            'held' => ['Order on hold: address check',
                "Order {$reference} ships to {$review->buyer_province}, outside your area. Zefanya will confirm the Buyer's address by phone first. You can accept and pack it now; pickup can be requested after the address is confirmed."],
            'confirmed' => ['Address confirmed: order released',
                "The delivery address for order {$reference} is confirmed. You can now request pickup."],
            default => ['Address not confirmed',
                "Zefanya could not confirm the delivery address for order {$reference}, so it stays on hold. You may decline the order from its page; stock and any voucher are returned as usual."],
        };

        return [
            'type' => 'address_check_'.$this->stage,
            'title' => $title,
            'seller_order_id' => $review->seller_order_id,
            'order_reference' => $reference,
            'message' => $message,
        ];
    }
}
