<?php

namespace App\Notifications\Admin;

use App\Models\Shared\BuyerConductReview;
use Illuminate\Notifications\Notification;

/** Staff-only: a Buyer's activity reached a review threshold. The Buyer is not told. */
class BuyerConductAlert extends Notification
{
    public function __construct(private readonly BuyerConductReview $review) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'buyer_conduct_alert',
            'title' => 'Buyer conduct review needed',
            'message' => $this->review->categoryLabel().' review '.$this->review->reference().' for Buyer #'.$this->review->buyer_id
                .'. No account action has been taken and the Buyer has not been notified.',
            'url' => route('admin.conduct-reviews.show', $this->review),
        ];
    }
}
