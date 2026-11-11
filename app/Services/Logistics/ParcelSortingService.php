<?php

namespace App\Services\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sorting a parcel at its final Station Hub: AtSortingCenter → Sorted.
 *
 * Sorting is a routing step, not a custody handover: the parcel is already in
 * the hub's verified custody. So when a Station Hub receipt scan lands the
 * parcel at its final sorting hub, that same scan sorts it and no second scan
 * is asked for. Parcels that still need a transfer are never sorted here.
 */
class ParcelSortingService
{
    public function __construct(
        private readonly SellerOrderStatusService $status = new SellerOrderStatusService,
        private readonly LogisticsHubService $hubs = new LogisticsHubService,
    ) {}

    /** The provider's active area covering the buyer's barangay, or null. */
    public function destinationArea(LogisticsProvider $provider, SellerOrder $parcel): ?Area
    {
        return $this->hubs->areaForBarangay($provider->id, $parcel->buyerBarangayCode());
    }

    /**
     * Sort under a row lock. The caller has already verified the scan token.
     * When the parcel is already sorted, this only re-checks operations.
     *
     * @throws ValidationException when an unresolved incident blocks the parcel
     * @throws \InvalidArgumentException when the parcel still needs a transfer
     */
    public function sort(
        SellerOrder $parcel,
        LogisticsProvider $provider,
        ?Area $area,
        ?string $method,
        bool $alreadySorted = false,
        string $noteSuffix = '',
    ): void {
        DB::transaction(function () use ($parcel, $provider, $area, $method, $alreadySorted, $noteSuffix): void {
            $fresh = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($fresh);
            if ($alreadySorted) {
                return;
            }

            if (($blocked = app(RouteExecutionService::class)->sortingBlockedReason($fresh)) !== null) {
                throw new \InvalidArgumentException($blocked);
            }

            if ($area !== null && $fresh->destination_area_id !== $area->id) {
                $fresh->forceFill(['destination_area_id' => $area->id])->save();
            }

            $base = $area !== null
                ? "Parcel sorted and routed to {$area->name}."
                : 'Parcel sorted and routed for delivery.';

            $this->status->transition(
                $fresh,
                OrderStatus::Sorted,
                note: $base.$noteSuffix.ParcelHandoffVerification::noteSuffix($method),
                changedBy: $provider->fullName(),
                actor: OrderActor::Logistics,
                actorId: $provider->id,
                source: 'web',
            );
        });
    }

    /**
     * Sort right after a verified Station Hub receipt, using that receipt's
     * scan as the proof. Returns null, leaving the parcel at the hub for the
     * normal sort scan, when the parcel is not at its final sorting hub or
     * sorting is refused; a receipt is never undone because sorting failed.
     *
     * @return array{area: ?Area}|null
     */
    public function sortAfterStationReceipt(SellerOrder $parcel, LogisticsProvider $provider, int $receivedHubId, ?string $method): ?array
    {
        $fresh = $parcel->fresh();
        if ($fresh === null || $fresh->status !== OrderStatus::AtSortingCenter) {
            return null;
        }

        if (app(RouteExecutionService::class)->sortingBlockedReason($fresh) !== null
            || $this->hubs->sortingHubId((int) $provider->id, $fresh) !== $receivedHubId) {
            return null;
        }

        $area = $this->destinationArea($provider, $fresh);

        try {
            $this->sort($fresh, $provider, $area, $method, noteSuffix: ' Sorted automatically on Station Hub receipt.');
        } catch (ValidationException|\InvalidArgumentException) {
            return null;
        }

        return ['area' => $area];
    }
}
