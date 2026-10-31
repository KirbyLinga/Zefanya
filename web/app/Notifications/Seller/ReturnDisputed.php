<?php

namespace App\Notifications\Seller;

use App\Models\Logistics\ParcelReturn;
use Illuminate\Notifications\Notification;

/** The buyer asked an admin to review this seller's rejection. The seller cannot act until that review. */
class ReturnDisputed extends Notification
{
    public function __construct(public ParcelReturn $returnRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $reference = $this->returnRequest->sellerOrder?->order?->reference;

        return [
            'type' => 'return_disputed',
            'seller_order_id' => $this->returnRequest->seller_order_id,
            'parcel_return_id' => $this->returnRequest->id,
            'order_reference' => $reference,
            'message' => "A buyer disputed your rejection of the return for order {$reference}. You cannot change it until an admin resolves it.",
        ];
    }
}
