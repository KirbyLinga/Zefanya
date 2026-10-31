<?php

namespace App\Notifications\Seller;

use App\Models\Seller\SellerPayoutRequest;
use Illuminate\Notifications\Notification;

class PayoutRequestDecided extends Notification
{
    public function __construct(public SellerPayoutRequest $payout) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $amount = '₱'.number_format($this->payout->amount_minor / 100, 2);
        $paid = $this->payout->status === SellerPayoutRequest::PAID;

        return [
            'type' => $paid ? 'seller_payout_paid' : 'seller_payout_rejected',
            'amount_minor' => $this->payout->amount_minor,
            'message' => $paid
                ? "Your payout of {$amount} was sent. Reference: {$this->payout->payout_reference}."
                : "Your payout request of {$amount} was rejected: {$this->payout->rejection_reason}. The earnings are available again.",
        ];
    }
}
