<?php

namespace App\Notifications\Buyer;

use App\Models\Logistics\ParcelReturn;
use Illuminate\Notifications\Notification;

/**
 * The Seller answered a buyer's return request, or the 3-day deadline answered for them.
 */
class ReturnRequestDecided extends Notification
{
    public function __construct(
        public ParcelReturn $returnRequest,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $reference = $this->returnRequest->sellerOrder?->order?->reference;

        return [
            'type' => 'return_request_decided',
            'seller_order_id' => $this->returnRequest->seller_order_id,
            'parcel_return_id' => $this->returnRequest->id,
            'order_reference' => $reference,
            'decision' => $this->returnRequest->seller_decision,
            'message' => match ($this->returnRequest->seller_decision) {
                ParcelReturn::REJECTED => "Your return request for order {$reference} was rejected. Reason: {$this->returnRequest->seller_decision_reason}",
                default => "Your return request for order {$reference} was approved.",
            },
        ];
    }
}
