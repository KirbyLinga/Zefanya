<?php

namespace App\Notifications\Seller;

use App\Models\Logistics\ParcelReturn;
use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Notification;

class BuyerReturnRequested extends Notification
{
    public function __construct(public SellerOrder $sellerOrder, public ParcelReturn $returnRequest) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'buyer_return_requested',
            'seller_order_id' => $this->sellerOrder->id,
            'parcel_return_id' => $this->returnRequest->id,
            'order_reference' => $this->sellerOrder->order?->reference,
            'reason' => $this->returnRequest->reason,
            'message' => 'Buyer requested a return for order '.$this->sellerOrder->order?->reference.'. Reason: '.$this->returnRequest->reason,
        ];
    }
}
