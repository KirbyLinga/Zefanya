<?php

namespace App\Services\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerOrderStatusService;

class ParcelTransitStatusService
{
    public function __construct(private readonly SellerOrderStatusService $statuses) {}

    public function departed(SellerOrder $parcel, LogisticsProvider $provider, string $note, string $source = 'manifest'): void
    {
        if (in_array($parcel->status, OrderStatus::AT_HUB, true)) {
            $this->statuses->recordTransferMovement($parcel, OrderStatus::InTransit, $provider->id, $note, $source);
        }
    }

    public function arrivedAtHub(SellerOrder $parcel, LogisticsProvider $provider, string $note, string $source = 'manifest', bool $resumeSorted = true): void
    {
        if ($parcel->status !== OrderStatus::InTransit) {
            return;
        }

        $wasSorted = $resumeSorted && $source === 'manifest'
            && $parcel->statusHistories()->where('to_status', OrderStatus::InTransit->value)->latest('id')->first()?->from_status === OrderStatus::Sorted;

        $this->statuses->recordTransferMovement($parcel, OrderStatus::ArrivedAtHub, $provider->id, $note, $source);
        if ($wasSorted) {
            $this->statuses->recordTransferMovement($parcel, OrderStatus::Sorted, $provider->id, 'Parcel was sorted before this transfer.', $source);
        }
    }
}
