<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Logistics\LogisticsHub;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only station stock: one row per parcel, never a count of history events. */
class StationInventoryService
{
    public function rows(int $providerId, Collection $stationIds): Collection
    {
        $stationIds = LogisticsHub::query()->where('logistics_provider_id', $providerId)
            ->where('hub_type', LogisticsHub::STATION)->whereIn('id', $stationIds)->pluck('id');
        if ($stationIds->isEmpty()) {
            return collect();
        }

        return DB::transaction(function () use ($providerId, $stationIds): Collection {
            $manifestTransit = fn ($q) => $q->whereNull('received_at')->whereNull('released_at')
                ->whereHas('manifest', fn ($m) => $m->where('status', ManifestStatus::Dispatched->value));
            $handoffTransit = fn ($q) => $q->where('status', ParcelHandoffStatus::InTransit->value);
            $parcels = SellerOrder::query()->where(fn ($q) => $q
                ->where(fn ($held) => $held->where('custody_provider_id', $providerId)->whereIn('custody_hub_id', $stationIds))
                ->orWhereHas('manifestItems', fn ($items) => $manifestTransit($items)
                    ->whereHas('manifest', fn ($m) => $m->where('logistics_provider_id', $providerId)->whereIn('destination_hub_id', $stationIds)))
                ->orWhereHas('parcelHandoffs', fn ($handoffs) => $handoffTransit($handoffs)
                    ->where('destination_provider_id', $providerId)->whereIn('receiving_hub_id', $stationIds)))
                ->with(['custodyProvider', 'parcelReturn.events', 'parcelReturn.originHub',
                    'manifestItems' => $manifestTransit, 'manifestItems.manifest.originHub', 'manifestItems.manifest.destinationHub',
                    'parcelHandoffs' => $handoffTransit, 'parcelHandoffs.sendingHub', 'parcelHandoffs.receivingHub'])
                ->withExists([
                    'manifestItems as inventory_manifest_recovery' => fn ($q) => $q->whereNull('received_at')->whereNull('recovered_at')
                        ->whereHas('manifest', fn ($m) => $m->where('status', ManifestStatus::Cancelled->value)->whereNotNull('dispatched_at')),
                    'parcelHandoffs as inventory_handoff_recovery' => fn ($q) => $q->where('status', ParcelHandoffStatus::Cancelled->value)
                        ->whereNotNull('dispatched_at')->whereNull('recovered_at')
                        ->where(fn ($actor) => $actor->whereNull('cancelled_by_type')->orWhere('cancelled_by_type', '!=', 'admin')),
                ])->orderBy('id')->get();

            $rows = collect();
            foreach ($parcels as $parcel) {
                $return = $parcel->parcelReturn;
                // Terminal return custody pointers are historical, not available stock.
                if ($return && in_array($return->status, [ParcelReturnStatus::Completed, ParcelReturnStatus::Lost,
                    ParcelReturnStatus::Damaged, ParcelReturnStatus::Closed], true)) {
                    continue;
                }
                $movements = $parcel->manifestItems->map(fn ($item) => [
                    'provider' => (int) $item->manifest->logistics_provider_id,
                    'station' => (int) $item->manifest->destination_hub_id,
                    'origin' => $item->manifest->originHub?->name, 'destination' => $item->manifest->destinationHub?->name,
                    'departed' => $item->manifest->dispatched_at?->getTimestamp() ?? 0,
                ])->concat($parcel->parcelHandoffs->map(fn ($handoff) => [
                    'provider' => (int) $handoff->destination_provider_id, 'station' => (int) $handoff->receiving_hub_id,
                    'origin' => $handoff->sendingHub?->name, 'destination' => $handoff->receivingHub?->name,
                    'departed' => $handoff->dispatched_at?->getTimestamp() ?? 0,
                ]));
                if ($movements->isNotEmpty()) {
                    // Retained sender custody does not mean physically inside after dispatch.
                    foreach ($movements->sortByDesc('departed')->take(1) as $movement) {
                        if ($movement['provider'] === $providerId && $stationIds->contains($movement['station'])) {
                            $rows->push(['parcel' => $parcel, 'station_id' => $movement['station'], 'inside' => false,
                                'to_sort' => false, 'exception' => false, 'origin' => $movement['origin'],
                                'destination' => $movement['destination'],
                                'note' => $movements->count() > 1 ? 'Conflicting transport records: investigate' : 'In transport; awaiting station receipt']);
                        }
                    }

                    continue;
                }
                if ((int) $parcel->custody_provider_id !== $providerId || ! $stationIds->contains($parcel->custody_hub_id)) {
                    continue;
                }
                if ($parcel->inventory_manifest_recovery || $parcel->inventory_handoff_recovery) {
                    continue;
                }
                $returnHeld = $return && match ($return->status) {
                    ParcelReturnStatus::Requested => ! $return->isBuyerRequest()
                        || ($return->collectedFromBuyer() && $return->hasStationReceipt()),
                    ParcelReturnStatus::InTransit, ParcelReturnStatus::AtOrigin,
                    ParcelReturnStatus::AwaitingSeller, ParcelReturnStatus::ReceiptDisputed => true,
                    default => false,
                };
                $outboundHeld = ! ($return?->blocksCompletion() ?? false) && in_array($parcel->status, [
                    OrderStatus::AtSortingCenter, OrderStatus::Sorted, OrderStatus::ArrivedAtHub,
                    OrderStatus::AssignedToRider, OrderStatus::DeliveryFailed,
                ], true);
                if ($outboundHeld && $parcel->status === OrderStatus::DeliveryFailed && ! $parcel->isBackAtStationAfterFailure()) {
                    $outboundHeld = false;
                }
                if (! $returnHeld && ! $outboundHeld) {
                    continue;
                }
                $rows->push(['parcel' => $parcel, 'station_id' => (int) $parcel->custody_hub_id, 'inside' => true,
                    'to_sort' => ! $returnHeld && in_array($parcel->status, [OrderStatus::AtSortingCenter, OrderStatus::ArrivedAtHub], true),
                    'exception' => false,
                    'origin' => null, 'destination' => $returnHeld ? $return->originHub?->name : null,
                    'note' => $returnHeld ? 'Continue the existing return workflow' : 'Station custody']);
            }
            $exceptions = Incidents::query()->whereIn('type', ParcelIncidentService::ACTIVE_TYPES)
                ->whereIn('incident_seller_order_id', $rows->where('inside', true)->pluck('parcel.id'))
                ->where(fn ($q) => $q->whereIn('status', [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value])
                    ->orWhere('resolution', ParcelIncidentService::HOLD))->pluck('incident_seller_order_id');

            return $rows->map(function (array $row) use ($exceptions): array {
                $row['exception'] = $row['exception'] || ($row['inside'] && $exceptions->contains($row['parcel']->id));

                return $row;
            });
        });
    }

    public function counts(Collection $rows): array
    {
        return ['inside' => $rows->where('inside', true)->count(), 'to_sort' => $rows->where('to_sort', true)->count(),
            'exceptions' => $rows->where('exception', true)->count(), 'inbound' => $rows->where('inside', false)->count(),
            'total' => $rows->count()];
    }
}
