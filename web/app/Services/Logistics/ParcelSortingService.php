<?php

namespace App\Services\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Support\Facades\DB;

class ParcelSortingService
{
    public function __construct(
        private readonly LogisticsHubService $hubs,
        private readonly RouteExecutionService $execution,
        private readonly SellerOrderStatusService $statuses,
    ) {}

    /**
     * A recorded Station receipt can also settle sorting at the final hub.
     * Without a saved route, validated delivery coverage identifies the final hub.
     */
    public function sortAfterStationReceipt(SellerOrder $parcel, LogisticsProvider $provider, int $hubId, ?string $method): ?array
    {
        return DB::transaction(function () use ($parcel, $provider, $hubId, $method): ?array {
            $current = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== OrderStatus::AtSortingCenter
                || $this->hubs->sortingHubId((int) $provider->id, $current) !== $hubId) {
                return null;
            }

            $route = $current->parcelRoute()->first();
            $area = $this->hubs->areaForBarangay((int) $provider->id, $current->buyerBarangayCode());
            $atDestination = $route === null
                ? $area !== null && (int) $area->station_hub_id === $hubId
                : (int) $route->destination_hub_id === $hubId && $this->execution->isAtFinalDestination($route);
            if (! $atDestination) {
                return null;
            }

            if ($area !== null) {
                $current->forceFill(['destination_area_id' => $area->id])->save();
            }
            $this->statuses->transition(
                $current,
                OrderStatus::Sorted,
                note: 'Sorted automatically on Station Hub receipt.'.ParcelHandoffVerification::noteSuffix($method),
                changedBy: $provider->fullName(),
                actor: OrderActor::Logistics,
                actorId: $provider->id,
                source: 'web',
            );

            return ['area' => $area];
        });
    }
}
