<?php

namespace App\Notifications\Rider;

use App\Models\Logistics\ParcelReturn;

/**
 * Logistics assigned this rider to fetch a return from the buyer's address.
 */
class BuyerReturnCollection extends RiderNotification
{
    public function __construct(
        public ParcelReturn $returnRequest,
    ) {}

    public function dedupeKey(): string
    {
        return 'buyer-return-collection:'.$this->returnRequest->id.':'.$this->returnRequest->collection_courier_id;
    }

    /** @return array<string, mixed> */
    protected function payload(): array
    {
        $parcel = $this->returnRequest->sellerOrder;

        return [
            'type' => 'rider_buyer_return_collection',
            'title' => 'Return to collect',
            'message' => 'Collect '.$this->parcelReference($parcel).' from the buyer and bring it to your Station Hub.',
            'url' => route('rider.return-pickups.index'),
            'seller_order_id' => $parcel->id,
            'tracking_number' => $parcel->tracking_number,
        ];
    }
}
