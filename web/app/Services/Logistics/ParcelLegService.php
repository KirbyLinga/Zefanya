<?php

namespace App\Services\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelRoute;
use App\Models\Shared\SellerOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages individual leg progression in multi-leg parcel routes.
 *
 * Bridges route planning (ParcelRoute legs) with execution workflows
 * (Manifest creation, ParcelHandoff receipt/dispatch). Ensures that
 * parcels progress through planned legs without skipping, duplicating,
 * or executing out of sequence.
 */
class ParcelLegService
{
    /** Readiness requires persisted receipt evidence and matching provider/hub custody. */
    public function isReadyForNextLeg(SellerOrder $order): bool
    {
        $order = $order->fresh();
        $route = $order?->parcelRoute;

        return $route !== null && ! $route->isCompleted() && $this->receiptMatches($order, $route);
    }

    /** Called inside the existing verified receiving transaction; legacy and reverse flows stay unchanged. */
    public function advanceAfterReceipt(SellerOrder $order, ManifestItem|ParcelHandoff $receipt): void
    {
        if ($order->hasActiveReturn()) {
            return;
        }
        if (app(ParcelIncidentService::class)->recordRecoveryReceipt($order, $receipt)) {
            return;
        }
        if ($order->parcelRoute()->exists()) {
            $this->advanceToNextLeg($order, $receipt);
        }
    }

    /** Recheck the locked source custody; a previously issued scan cannot overwrite changed custody. */
    public function requireReceiptOrigin(SellerOrder $order, int $providerId, int $hubId): void
    {
        if (! $order->parcelRoute()->exists() || $order->hasActiveReturn()) {
            return;
        }
        if ((int) $order->custody_provider_id !== $providerId || (int) $order->custody_hub_id !== $hubId) {
            throw ValidationException::withMessages(['route' => 'Verified origin custody is required before receiving this saved route leg.']);
        }
    }

    /** The pointer identifies the next outstanding transfer, and stays on the final leg after completion. */
    public function advanceToNextLeg(SellerOrder $order, ManifestItem|ParcelHandoff|null $receipt = null): void
    {
        DB::transaction(function () use ($order, $receipt): void {
            $current = SellerOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($current);
            $route = $current->parcelRoute()->lockForUpdate()->first();
            if (! $route) {
                throw ValidationException::withMessages(['route' => 'No active route found.']);
            }
            if ($route->isCompleted() && $receipt === null) {
                return;
            }
            if (! $this->receiptMatches($current, $route, $receipt)) {
                throw ValidationException::withMessages([
                    'route' => 'Verified receipt, expected route endpoints, and provider custody must match before advancing.',
                ]);
            }
            if ($route->isCompleted()) {
                return;
            }
            // Receipt is historical fact. Future connection availability is enforced at departure.
            if ($route->isCurrentLegFinal()) {
                if ((int) ($route->currentLegDestinationHubId() ?? $route->origin_hub_id) !== (int) $route->destination_hub_id) {
                    throw ValidationException::withMessages(['route' => 'The final leg does not reach the saved destination.']);
                }
                $route->update(['route_completed_at' => now()]);
            } else {
                $route->increment('current_leg_index');
            }
        });
        $order->unsetRelation('parcelRoute');
    }

    /** Record the existing F2 station receipt, completing a zero-transfer route only after that receipt. */
    public function receiveAtOrigin(SellerOrder $order, int $providerId, int $hubId): void
    {
        DB::transaction(function () use ($order, $providerId, $hubId): void {
            $current = SellerOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($current);
            $route = $current->parcelRoute()->lockForUpdate()->first();
            if (! $route) {
                return;
            }
            $hub = LogisticsHub::query()->find($hubId);
            if (! $hub || (int) $hub->logistics_provider_id !== $providerId
                || (int) $route->origin_hub_id !== $hubId || ! $this->hasOriginReceipt($current, $providerId)) {
                throw ValidationException::withMessages(['route' => 'Origin receipt must match the saved origin and verified provider.']);
            }
            $current->forceFill(['custody_provider_id' => $providerId, 'custody_hub_id' => $hubId])->save();
            if ($route->totalLegs() === 0) {
                $this->advanceToNextLeg($current);
            }
        });
    }

    private function hasOriginReceipt(SellerOrder $order, int $providerId): bool
    {
        return $order->handover_at !== null && $order->statusHistories()
            ->where('to_status', OrderStatus::AtSortingCenter->value)
            ->where('actor_type', OrderActor::Logistics->value)->where('actor_id', $providerId)->exists();
    }

    private function receiptMatches(SellerOrder $order, ParcelRoute $route, ManifestItem|ParcelHandoff|null $receipt = null): bool
    {
        $legs = is_array($route->legs) ? $route->legs : json_decode($route->legs ?: '[]', true);
        $destinationId = $route->totalLegs() === 0 ? (int) $route->destination_hub_id : $route->currentLegDestinationHubId();
        $destination = LogisticsHub::query()->find($destinationId);
        if (! $destination || (int) $order->custody_hub_id !== (int) $destinationId
            || (int) $order->custody_provider_id !== (int) $destination->logistics_provider_id) {
            return false;
        }
        if ($route->totalLegs() === 0) {
            return (int) $route->origin_hub_id === (int) $route->destination_hub_id
                && $this->hasOriginReceipt($order, (int) $destination->logistics_provider_id);
        }
        $originId = $route->current_leg_index === 0 ? $route->origin_hub_id : ($legs[$route->current_leg_index - 1]['hub_id'] ?? null);
        $originId = $route->currentLeg()['origin_hub_id'] ?? $originId;
        $origin = LogisticsHub::query()->find($originId);
        if (! $origin) {
            return false;
        }

        return $this->verifiedReceipt($order, $origin, $destination, $receipt);
    }

    private function verifiedReceipt(SellerOrder $order, ?LogisticsHub $origin, LogisticsHub $destination, ManifestItem|ParcelHandoff|null $receipt = null): bool
    {
        $providerId = (int) $destination->logistics_provider_id;
        if (! $receipt || $receipt instanceof ManifestItem) {
            $manifest = $order->manifestItems()->whereNotNull('received_at')
                ->where('received_by_type', 'logistics')->where('received_by_id', $providerId)
                ->when($receipt, fn ($query) => $query->whereKey($receipt->id))
                ->whereHas('manifest', function ($query) use ($origin, $destination, $providerId): void {
                    $query->where('destination_hub_id', $destination->id)->where('logistics_provider_id', $providerId)
                        ->where('direction', 'forward')->whereNotNull('dispatched_at');
                    if ($origin) {
                        $query->where('origin_hub_id', $origin->id);
                    }
                })->exists();
            if ($manifest && (! $origin || (int) $origin->logistics_provider_id === $providerId)) {
                return true;
            }
        }
        if (! $receipt || $receipt instanceof ParcelHandoff) {
            return $order->parcelHandoffs()->where('receiving_hub_id', $destination->id)
                ->where('destination_provider_id', $providerId)->where('direction', 'forward')
                ->where('status', ParcelHandoffStatus::Received->value)
                ->whereNotNull('accepted_at')->whereNotNull('dispatched_at')->whereNotNull('received_at')
                ->where('received_by_type', 'logistics')->where('received_by_id', $providerId)
                ->when($origin, fn ($query) => $query->where('sending_hub_id', $origin->id)->where('origin_provider_id', $origin->logistics_provider_id))
                ->when($receipt, fn ($query) => $query->whereKey($receipt->id))->exists();
        }

        return false;
    }

    /** Physical location requires custody plus receipt evidence, never a planned destination. */
    public function verifiedLocation(SellerOrder $order): ?LogisticsHub
    {
        $hub = LogisticsHub::query()->find($order->custody_hub_id);
        if (! $hub || (int) $order->custody_provider_id !== (int) $hub->logistics_provider_id) {
            return null;
        }
        if ($this->verifiedReceipt($order, null, $hub)
            || ((int) $order->origin_station_hub_id === (int) $hub->id && $this->hasOriginReceipt($order, (int) $hub->logistics_provider_id))) {
            return $hub;
        }

        return null;
    }

    /** Complete only a physically verified leg; dispatch and acceptance never call this. */
    public function completeLeg($routeOrOrder, ?int $legIndex = null): void
    {
        $order = $routeOrOrder instanceof ParcelRoute ? $routeOrOrder->sellerOrder : $routeOrOrder;
        $route = $order?->parcelRoute()->first();
        if (! $route || ($legIndex !== null && $legIndex !== $route->current_leg_index)) {
            throw ValidationException::withMessages(['route' => 'The requested leg is not the current saved leg.']);
        }
        $this->advanceToNextLeg($order);
    }

    /**
     * Get human-readable status of current leg.
     */
    public function legStatusDescription(SellerOrder $order): string
    {
        $route = $order->parcelRoute;
        if (! $route) {
            return 'No route planned.';
        }

        if ($route->isCompleted()) {
            return 'Route completed - at destination.';
        }

        $currentLeg = $route->currentLeg();
        if (! $currentLeg) {
            return 'Route invalid - no current leg.';
        }

        $legNum = $route->current_leg_index + 1;
        $totalLegs = $route->totalLegs();
        $currentHub = LogisticsHub::query()
            ->find($currentLeg['hub_id'])
            ?->name ?? "Hub {$currentLeg['hub_id']}";
        $transportMode = $currentLeg['transport_mode'] ?? 'UNKNOWN';

        return "Leg {$legNum}/{$totalLegs}: {$currentHub} via {$transportMode}";
    }
}
