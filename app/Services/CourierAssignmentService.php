<?php

namespace App\Services;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\DeliveryAssignment;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use Illuminate\Support\Facades\DB;

class CourierAssignmentService
{
    public function __construct(
        private readonly SellerOrderStatusService $status = new SellerOrderStatusService,
    ) {}

    /**
     * Assign a PICKUP courier (from the acting provider's fleet) and advance
     * the order to PickedUp. The courier becomes busy and is recorded on
     * courier_id.
     *
     * Concurrency: the seller_order row is locked for update inside the
     * transaction and courier_id is re-checked so two providers racing on the
     * same unassigned parcel produce exactly one winner.
     *
     * @param  int|null  $preferredCourierId  Logistics may pick a specific rider.
     * @param  int|null  $areaId  Restrict/lower-priority the match to this area.
     *
     * @throws \InvalidArgumentException when the acting provider does not own
     *                                   the matched courier (should not happen with provider filtering
     *                                   but is a hard server-side guard).
     */
    public function assignPickup(SellerOrder $sellerOrder, ?LogisticsProvider $provider = null, ?int $preferredCourierId = null): ?Courier
    {
        return DB::transaction(function () use ($sellerOrder, $provider, $preferredCourierId): ?Courier {
            $order = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->lockForUpdate()
                ->first();

            if ($order === null || $order->courier_id !== null) {
                return null;
            }

            if ($order->status !== OrderStatus::ReadyForPickup) {
                return null;
            }

            // Pickup happens at the seller's shop, so the rider's service area
            // must cover the SELLER's municipality. Fall back to the buyer's
            // only when the seller has no recorded municipality (legacy /
            // manual-mode rows) — that keeps the previous behaviour instead of
            // silently assigning nobody.
            $courier = $this->candidateFor(
                $order,
                $order->sellerMunicipality() ?? $order->buyerMunicipality(),
                $provider,
                $preferredCourierId,
            );

            if ($courier === null) {
                return null;
            }

            $this->assertCourierBelongsToProvider($courier, $provider);

            $order->forceFill(['courier_id' => $courier->id])->save();
            $courier->forceFill(['is_available' => false])->save();

            $this->recordAssignment($order, $courier, 'pickup', $provider);

            $this->status->transition(
                $order,
                OrderStatus::PickedUp,
                note: "Pickup courier {$courier->name} assigned and parcel collected.",
                changedBy: 'system',
                actor: OrderActor::Rider,
                actorId: $courier->id,
                source: 'web',
            );

            return $courier;
        });
    }

    /**
     * Assign a DELIVERY courier (from the acting provider's fleet) and advance
     * the order to AssignedToRider. The delivery courier becomes busy and is
     * recorded on delivery_courier_id. The pickup courier (courier_id) is
     * preserved.
     *
     * Selection order: an explicitly chosen rider wins; otherwise a rider
     * linked to the parcel's destination AREA is preferred, falling back to a
     * municipality service-area match.
     *
     * Concurrency: the seller_order row is locked for update inside the
     * transaction and delivery_courier_id is re-checked so two providers
     * racing on the same parcel produce exactly one winner.
     */
    public function assignDelivery(SellerOrder $sellerOrder, ?LogisticsProvider $provider = null, ?int $preferredCourierId = null, ?int $areaId = null): ?Courier
    {
        return DB::transaction(function () use ($sellerOrder, $provider, $preferredCourierId, $areaId): ?Courier {
            $order = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->lockForUpdate()
                ->first();

            if ($order === null || $order->delivery_courier_id !== null) {
                return null;
            }

            // Delivery happens at the buyer's address — match the destination.
            $courier = $this->candidateFor(
                $order,
                $order->buyerMunicipality(),
                $provider,
                $preferredCourierId,
                $areaId,
            );

            if ($courier === null) {
                return null;
            }

            $this->assertCourierBelongsToProvider($courier, $provider);

            $order->forceFill(['delivery_courier_id' => $courier->id])->save();
            $courier->forceFill(['is_available' => false])->save();

            $this->recordAssignment($order, $courier, 'delivery', $provider);

            $this->status->transition(
                $order,
                OrderStatus::AssignedToRider,
                note: "Delivery courier {$courier->name} assigned.",
                changedBy: 'system',
                actor: OrderActor::Logistics,
                actorId: $courier->id,
                source: 'web',
            );

            return $courier;
        });
    }

    /**
     * Release a courier back to available when their run is done.
     */
    public function release(Courier $courier): void
    {
        $courier->forceFill(['is_available' => true])->save();
    }

    /**
     * @deprecated Use assignPickup() or assignDelivery() instead.
     * Kept for backward compatibility with existing call sites.
     */
    public function assign(SellerOrder $sellerOrder, ?LogisticsProvider $provider = null): ?Courier
    {
        return $this->assignDelivery($sellerOrder, $provider);
    }

    /**
     * Append an assignment-history row. Best-effort: the history table is part
     * of the same transaction, so a failure rolls the assignment back too.
     */
    private function recordAssignment(SellerOrder $order, Courier $courier, string $role, ?LogisticsProvider $provider): void
    {
        DeliveryAssignment::query()->create([
            'seller_order_id' => $order->id,
            'courier_id' => $courier->id,
            'role' => $role,
            'assigned_by_type' => $provider !== null ? 'logistics' : 'system',
            'assigned_by_id' => $provider?->getKey(),
        ]);
    }

    private function candidateFor(SellerOrder $sellerOrder, ?string $municipality, ?LogisticsProvider $provider = null, ?int $preferredCourierId = null, ?int $areaId = null): ?Courier
    {
        $weight = $sellerOrder->parcelWeightGrams();

        // 1. An explicitly chosen rider wins, as long as they can carry the
        //    parcel and are free.
        if ($preferredCourierId !== null) {
            $courier = Courier::query()
                ->when($provider !== null, fn ($q) => $q->where('logistics_provider_id', $provider->getKey()))
                ->whereKey($preferredCourierId)
                ->first();

            return ($courier !== null
                && $courier->is_available
                && (int) $courier->max_weight_grams >= $weight)
                ? $courier
                : null;
        }

        // 2. Prefer a rider linked to the parcel's destination area.
        if ($areaId !== null) {
            $byArea = Courier::query()
                ->when($provider !== null, fn ($q) => $q->where('logistics_provider_id', $provider->getKey()))
                ->where('area_id', $areaId)
                ->where('is_available', true)
                ->where('max_weight_grams', '>=', $weight)
                ->orderBy('max_weight_grams', 'asc')
                ->first();

            if ($byArea !== null) {
                return $byArea;
            }
        }

        // 3. Fall back to a service-area (municipality) match.
        if ($municipality === null) {
            return null;
        }

        return Courier::query()
            ->when($provider !== null, fn ($q) => $q->where('logistics_provider_id', $provider->getKey()))
            ->where('is_available', true)
            ->where('max_weight_grams', '>=', $weight)
            ->orderBy('max_weight_grams', 'asc')
            ->get()
            ->first(fn (Courier $courier) => $this->servesMunicipality($courier, $municipality));
    }

    private function servesMunicipality(Courier $courier, string $municipality): bool
    {
        return in_array($municipality, $courier->service_area ?? [], true);
    }

    /**
     * Hard server-side guard: the matched courier MUST belong to the acting
     * provider. Even though candidateFor() already filters by provider when
     * one is passed, this is a belt-and-suspenders check for direct service
     * callers that pass a provider after manually selecting a courier.
     *
     * @throws \InvalidArgumentException if the courier belongs to a different
     *                                   provider (mapped to HTTP 403 at the controller layer).
     */
    private function assertCourierBelongsToProvider(Courier $courier, ?LogisticsProvider $provider): void
    {
        if ($provider !== null && $courier->logistics_provider_id !== $provider->getKey()) {
            throw new \InvalidArgumentException(
                "Courier '{$courier->name}' does not belong to your logistics provider."
            );
        }
    }
}
