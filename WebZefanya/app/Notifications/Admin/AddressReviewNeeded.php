<?php

namespace App\Notifications\Admin;

use App\Models\Shared\OrderAddressReview;
use Illuminate\Notifications\Notification;

/** Staff: a cross-region shipment is waiting for a phone check before pickup. */
class AddressReviewNeeded extends Notification
{
    public function __construct(private readonly OrderAddressReview $review) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'address_review_needed',
            'title' => 'Address check needed',
            'message' => 'Shipment #'.$this->review->seller_order_id.' goes from '.$this->review->regionLabel($this->review->seller_region)
                .' to '.$this->review->buyer_province.' ('.$this->review->regionLabel($this->review->buyer_region).'). Call the Buyer to confirm the address before pickup.',
            'url' => route('admin.address-reviews.show', $this->review->id),
        ];
    }
}
