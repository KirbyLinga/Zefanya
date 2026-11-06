<?php

namespace App\Notifications\Buyer;

use App\Models\Logistics\ParcelReturn;
use Illuminate\Notifications\Notification;

/** An admin closed a return dispute, for the buyer and the seller. */
class ReturnDisputeResolved extends Notification
{
    public function __construct(public ParcelReturn $returnRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $reference = $this->returnRequest->sellerOrder?->order?->reference;
        $kept = $this->returnRequest->dispute_outcome === ParcelReturn::DISPUTE_UPHELD;

        return [
            'type' => 'return_dispute_resolved',
            'seller_order_id' => $this->returnRequest->seller_order_id,
            'parcel_return_id' => $this->returnRequest->id,
            'order_reference' => $reference,
            'decision' => $this->returnRequest->dispute_outcome,
            'message' => $kept
                ? "An admin kept the rejection of the return for order {$reference}. {$this->returnRequest->dispute_note}"
                : "An admin approved the return for order {$reference} after the dispute. {$this->returnRequest->dispute_note}",
        ];
    }
}
