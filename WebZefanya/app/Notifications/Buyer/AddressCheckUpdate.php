<?php

namespace App\Notifications\Buyer;

use App\Models\Shared\OrderAddressReview;
use Illuminate\Notifications\Notification;

/** Tells the Buyer a phone check is coming, and its result. Never accusatory. */
class AddressCheckUpdate extends Notification
{
    /** @param  'requested'|'confirmed'|'not_confirmed'  $stage */
    public function __construct(public OrderAddressReview $review, public string $stage) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $review = $this->review;
        $reference = $review->sellerOrder?->order?->reference ?? 'your order';
        $phone = self::masked($review->contact_no);

        [$title, $message] = match ($this->stage) {
            'requested' => ['Delivery address check',
                "Before order {$reference} ships to {$review->buyer_province}, Zefanya will call {$phone} to confirm your delivery address. Please keep your phone reachable. You do not need to do anything else."],
            'confirmed' => ['Delivery address confirmed',
                "Thank you. Your delivery address for order {$reference} is confirmed and the shop can now send it."],
            default => ['Delivery address not confirmed',
                "We could not confirm the delivery address for order {$reference}, so it has not shipped. The shop may cancel the order; any items are returned to stock and you are not charged. To resolve this, contact Zefanya support through the Help page and quote {$review->reference()}."],
        };

        return [
            'type' => 'address_check_'.$this->stage,
            'title' => $title,
            'seller_order_id' => $review->seller_order_id,
            'order_reference' => $reference,
            'review_reference' => $review->reference(),
            'message' => $message,
        ];
    }

    /** "0917•••4567": enough to recognise, not enough to copy. */
    public static function masked(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if (strlen($digits) < 7) {
            return 'the phone number on your order';
        }

        return substr($digits, 0, 4).'•••'.substr($digits, -4);
    }
}
