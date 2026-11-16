<?php

namespace App\Services\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerOrderStatusService;

/**
 * Keeps a parcel's delivery status in step with its hub-to-hub movement:
 *  - a verified dispatch scan moves a parcel waiting at a hub to IN_TRANSIT;
 *  - only a verified receiving (or recovery) scan moves it to ARRIVED_AT_HUB.
 *
 * Call inside the movement's transaction with the parcel row locked. Returns
 * and other parcels outside the forward pipeline keep their status: their
 * physical movement is tracked by the return workflow and the custody
 * history, never by guessing a delivery status.
 */
class ParcelTransitStatusService
{
    public function __construct(private readonly SellerOrderStatusService $statuses) {}

    public function departed(SellerOrder $locked, LogisticsProvider|int $provider, string $note, string $source = 'manifest'): bool
    {
        if (! in_array($locked->status, OrderStatus::AT_HUB, true) || $locked->hasActiveReturn()) {
            return false;
        }

        return $this->statuses->recordTransferMovement($locked, OrderStatus::InTransit, $this->providerId($provider), $note, $source);
    }

    /**
     * A verified receipt (or recovery) scan at a hub. Call after the route has
     * advanced for this receipt. A parcel that was already sorted before it
     * left goes straight back to SORTED once it reaches its final hub, so it
     * is ready for delivery as before; sorting is never skipped for a parcel
     * that was not sorted yet.
     */
    public function arrivedAtHub(SellerOrder $locked, LogisticsProvider|int $provider, string $note, string $source = 'manifest', bool $resumeSorted = true): bool
    {
        if ($locked->status !== OrderStatus::InTransit) {
            return false;
        }

        $providerId = $this->providerId($provider);
        $moved = $this->statuses->recordTransferMovement($locked, OrderStatus::ArrivedAtHub, $providerId, $note, $source);

        if ($moved && $resumeSorted && $this->sortedBeforeTransfer($locked)
            && ! $locked->hasActiveReturn()
            && app(RouteExecutionService::class)->sortingBlockedReason($locked) === null) {
            $this->statuses->recordTransferMovement($locked, OrderStatus::Sorted, $providerId,
                'Already sorted before transfer; ready for delivery here.', $source);
        }

        return $moved;
    }

    private function sortedBeforeTransfer(SellerOrder $parcel): bool
    {
        // Sorted at some hub, or it left a hub on a transfer while SORTED.
        return $parcel->statusHistories()
            ->where(fn ($history) => $history->where('to_status', OrderStatus::Sorted->value)
                ->orWhere(fn ($left) => $left->where('to_status', OrderStatus::InTransit->value)
                    ->where('from_status', OrderStatus::Sorted->value)))
            ->exists();
    }

    private function providerId(LogisticsProvider|int $provider): int
    {
        return $provider instanceof LogisticsProvider ? (int) $provider->id : $provider;
    }
}
