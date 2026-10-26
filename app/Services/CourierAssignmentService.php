<?php

namespace App\Services;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\DeliveryAssignment;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider;
use App\Models\SellerOrder;
use App\Notifications\Rider\AssignmentReassigned;
use App\Notifications\Rider\DeliveryAssigned;
use App\Notifications\Rider\PickupAssigned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CourierAssignmentService
{
    public function __construct(
        private readonly SellerOrderStatusService $status = new SellerOrderStatusService,
        private readonly RiderNotifier $riderNotifier = new RiderNotifier,
        private readonly LogisticsHubService $hubs = new LogisticsHubService,
    ) {}

    /** Run only after the physical/status transaction commits, including outer transactions. */
    public function dispatchAfterCommit(SellerOrder $parcel): void
    {
        DB::afterCommit(function () use ($parcel): void {
            $current = $parcel->fresh();
            if ($current === null || $current->currentCustodyProviderId() === null) {
                return;
            }
            if ($current->status === OrderStatus::ReadyForPickup) {
                $result = $this->dispatch($current, 'pickup');
                // Automatic pickup routing reserves and notifies in one step. The
                // Rider still confirms the physical collection with F2.
                if ($result['status'] === 'reserved' && $result['courier'] !== null) {
                    $provider = $current->carrierProvider;
                    if ($provider !== null) {
                        $this->confirmPickupDispatch($current->fresh(), $provider);
                    }
                }
            } elseif ($current->status === OrderStatus::Sorted) {
                $this->dispatch($current, 'delivery');
            }
        });
    }

    /** Idempotent automatic reservation. Canonical status changes require the existing actions. */
    public function dispatch(SellerOrder $parcel, string $role, ?LogisticsProvider $actor = null): array
    {
        if (! in_array($role, ['pickup', 'delivery'], true)) {
            throw ValidationException::withMessages(['role' => 'Choose pickup or delivery dispatch.']);
        }
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('advance-shipment', $parcel);
        }
        $providerId = $role === 'pickup' ? $parcel->carrier_provider_id : $parcel->currentCustodyProviderId();
        $providerId ??= $actor?->id;
        if ($providerId === null) {
            return ['status' => 'unavailable', 'courier' => null, 'message' => 'This legacy parcel needs an authorized carrier before automatic dispatch.'];
        }

        $result = DB::transaction(function () use ($parcel, $role, $actor, $providerId): array {
            $this->hubs->lockProvider((int) $providerId);
            $current = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($current);
            $provider = LogisticsProvider::query()->findOrFail($providerId);
            $currentProviderId = $role === 'pickup' ? $current->carrier_provider_id : $current->currentCustodyProviderId();
            if ($currentProviderId !== null && (int) $currentProviderId !== (int) $providerId) {
                return ['status' => 'not_eligible', 'courier' => null, 'message' => 'Parcel custody changed. Refresh before dispatching.'];
            }
            if ($actor !== null) {
                Gate::forUser($actor)->authorize('advance-shipment', $current);
                abort_unless((int) $actor->id === (int) $providerId, 403);
            }
            if ($current->hasActiveReturn()) {
                return ['status' => 'not_eligible', 'courier' => null, 'message' => 'This parcel is in the return workflow.'];
            }
            $column = $role === 'pickup' ? 'courier_id' : 'delivery_courier_id';
            $expected = $role === 'pickup' ? OrderStatus::ReadyForPickup : OrderStatus::Sorted;
            if ($current->status !== $expected) {
                return ['status' => 'not_eligible', 'courier' => null, 'message' => 'This parcel is not awaiting '.$role.' dispatch.'];
            }
            if ($current->{$column} !== null) {
                $courier = Courier::query()->findOrFail($current->{$column});
                abort_unless((int) $courier->logistics_provider_id === (int) $providerId, 403);
                if (! $courier->isEligibleForAssignment() || $current->needs_reassignment) {
                    if (! $current->needs_reassignment) {
                        $current->flagForReassignment('The reserved Rider needs an authorized manual reassignment.');
                    }

                    return ['status' => 'unavailable', 'courier' => $courier, 'message' => 'The reserved Rider is unavailable. Reassign within the same station and provide a reason.'];
                }

                return ['status' => 'assigned', 'courier' => $courier, 'message' => 'A Rider is already reserved.'];
            }
            $blocked = ! $provider->isApproved() || ! $provider->hasVerifiedProvince() || $provider->archived_at !== null
                ? 'The authorized provider is not operational.' : $this->hubs->assignmentBlockReason((int) $providerId);
            if ($role === 'delivery' && ! $this->deliveryReadyAtStation($current, (int) $providerId)) {
                $blocked = 'Awaiting verified custody and arrival at the destination Station Hub.';
            }
            $courier = null;
            if ($blocked === null) {
                if ($role === 'pickup') {
                    $courier = $this->reservePickup($current, $provider);
                } else {
                    $courier = $this->assignDelivery($current, $provider, reserveOnly: true);
                }
            }
            if ($courier === null) {
                $message = $blocked ?? 'No available eligible Rider covers this barangay at the required station. Retry manual dispatch or review Rider availability.';
                $current->flagForReassignment('Automatic '.$role.' dispatch: '.$message);

                return ['status' => 'unavailable', 'courier' => null, 'message' => $message];
            }
            $assignment = $current->deliveryAssignments()->where('role', $role)->latest('id')->firstOrFail();
            $assignment->forceFill(['assigned_by_type' => 'system', 'assigned_by_id' => null,
                'notes' => $role === 'pickup'
                    ? 'Reserved at pickup by the assigned logistics provider. Awaiting the assigned rider to confirm collection.'
                    : 'Reserved for delivery by the assigned logistics provider. Awaiting logistics dispatch confirmation.'])->save();

            return ['status' => 'reserved', 'courier' => $courier, 'message' => $role === 'pickup'
                ? 'Pickup rider reserved for logistics to confirm dispatch.'
                : 'Delivery rider reserved for logistics to confirm dispatch.'];
        });

        return $result;
    }

    public function deliveryReadyAtStation(SellerOrder $parcel, int $providerId): bool
    {
        if (($parcel->currentCustodyProviderId() !== null && $parcel->currentCustodyProviderId() !== $providerId)
            || ($parcel->handoff_destination_provider_id !== null && $parcel->custody_provider_id === null)
            || $parcel->manifestItems()->whereNull('released_at')->exists()
            || $parcel->parcelHandoffs()->whereIn('status', ParcelHandoffService::ACTIVE_STATUSES)->exists()) {
            return false;
        }
        $area = $this->hubs->areaForBarangay($providerId, $parcel->buyerBarangayCode(), true);

        return $area !== null && (new ManifestService)->currentHubId($parcel) === (int) $area->station_hub_id;
    }

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
        $assignment = null;
        $providerIds = $this->assignmentProviderIds($provider, $sellerOrder->sellerBarangayCode());

        $courier = DB::transaction(function () use ($sellerOrder, $provider, $preferredCourierId, &$assignment, $providerIds): ?Courier {
            foreach ($providerIds as $providerId) {
                $this->hubs->lockProvider($providerId);
            }
            $order = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->lockForUpdate()
                ->first();
            if ($order !== null) {
                ParcelIncidentService::assertOperationsAllowed($order);
            }

            if ($order === null || $order->courier_id !== null) {
                return null;
            }

            if ($order->status !== OrderStatus::ReadyForPickup) {
                return null;
            }

            // Pickup uses the seller origin barangay; missing codes fail closed.
            $courier = $this->candidateFor(
                $order,
                $order->sellerBarangayCode(),
                $provider,
                $preferredCourierId,
                providerIds: $providerIds,
                stationHubId: $order->origin_station_hub_id !== null ? (int) $order->origin_station_hub_id : null,
            );

            if ($courier === null) {
                return null;
            }

            $this->assertCourierBelongsToProvider($courier, $provider);

            $order->forceFill([
                'courier_id' => $courier->id,
                'needs_reassignment' => false,
                'reassignment_reason' => null,
                'reassignment_flagged_at' => null,
            ])->save();
            $courier->forceFill(['is_available' => false])->save();

            // Freeze origin Station Hub from coverage at routing time.
            $this->hubs->assignOriginStationIfMissing($order, (int) $courier->logistics_provider_id);

            $assignment = $this->recordAssignment($order, $courier, 'pickup', $provider);

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
        }, 3);

        // Dispatch only after the transaction committed; a failed assignment
        // (or a rolled-back transition) never produces a notification.
        if ($courier !== null && $assignment !== null) {
            $this->riderNotifier->send(
                $courier->rider,
                new PickupAssigned($sellerOrder->fresh(), $assignment->id),
            );
        }

        return $courier;
    }

    /**
     * Assign a DELIVERY courier (from the acting provider's fleet) and advance
     * the order to AssignedToRider. The delivery courier becomes busy and is
     * recorded on delivery_courier_id. The pickup courier (courier_id) is
     * preserved.
     *
     * Selection order: an explicitly chosen rider wins; otherwise a rider
     * must belong to the active station covering the saved delivery barangay.
     *
     * Concurrency: the seller_order row is locked for update inside the
     * transaction and delivery_courier_id is re-checked so two providers
     * racing on the same parcel produce exactly one winner.
     */
    public function assignDelivery(
        SellerOrder $sellerOrder,
        ?LogisticsProvider $provider = null,
        ?int $preferredCourierId = null,
        ?int $areaId = null,
        bool $rescheduled = false,
        ?Courier $replacedCourier = null,
        bool $reserveOnly = false,
        ?string $reason = null,
    ): ?Courier {
        $assignment = null;
        $providerIds = $this->assignmentProviderIds($provider ?? $sellerOrder->custodyProvider ?? $sellerOrder->carrierProvider ?? $sellerOrder->courier?->provider, $sellerOrder->buyerBarangayCode());

        $courier = DB::transaction(function () use ($sellerOrder, $provider, $preferredCourierId, $areaId, $rescheduled, &$replacedCourier, &$assignment, $providerIds, $reserveOnly, $reason): ?Courier {
            foreach ($providerIds as $providerId) {
                $this->hubs->lockProvider($providerId);
            }
            $order = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->lockForUpdate()
                ->first();
            if ($order !== null) {
                ParcelIncidentService::assertOperationsAllowed($order);
            }

            if ($order === null) {
                return null;
            }
            if ($reserveOnly && $order->status !== OrderStatus::Sorted) {
                return null;
            }
            if ($rescheduled && (! SellerOrderStatusService::canRetryDelivery($order)
                || (int) $order->delivery_courier_id !== (int) $sellerOrder->delivery_courier_id)) {
                return null;
            }
            if ($order->status === OrderStatus::DeliveryFailed && ! SellerOrderStatusService::canRetryDelivery($order)) {
                return null;
            }
            if ($order->delivery_courier_id !== null) {
                if (! $rescheduled && $order->status === OrderStatus::Sorted && ! $reserveOnly) {
                    $existing = $order->deliveryCourier;
                    $owner = $provider ?? $existing?->provider;
                    if ($existing === null || $owner === null
                        || ! Gate::forUser($owner)->allows('advance-shipment', $order)
                        || ! $existing->isEligibleForAssignment()
                        || ! $this->deliveryReadyAtStation($order, (int) $owner->id)
                        || ($preferredCourierId !== null && $preferredCourierId !== (int) $existing->id)) {
                        return null;
                    }
                    $this->status->transition($order, OrderStatus::AssignedToRider,
                        note: 'Logistics confirmed the reserved delivery Rider.',
                        actor: OrderActor::Logistics, actorId: $owner->id, source: 'web');
                    $assignment = $order->deliveryAssignments()->where('role', 'delivery')->latest('id')->first();

                    return $existing;
                }
                if (! $rescheduled) {
                    return null;
                }
                $replacedCourier = $order->deliveryCourier;
            }

            if ($provider !== null && (($order->currentCustodyProviderId() !== null
                && $order->currentCustodyProviderId() !== (int) $provider->id)
                || ($order->currentCustodyProviderId() === null && $order->courier_id !== null
                    && (int) $order->courier?->logistics_provider_id !== (int) $provider->id))) {
                return null;
            }
            // Delivery happens at the buyer's address — match the destination.
            $authorityId = $order->currentCustodyProviderId() ?? $provider?->id ?? $order->courier?->logistics_provider_id;
            if ($authorityId === null || ! $this->deliveryReadyAtStation($order, (int) $authorityId)) {
                return null;
            }
            $courier = $this->candidateFor(
                $order,
                $order->buyerBarangayCode(),
                $provider,
                $preferredCourierId,
                $areaId,
                providerIds: $providerIds,
            );

            if ($courier === null) {
                return null;
            }

            $this->assertCourierBelongsToProvider($courier, $provider);

            if ($order->custody_hub_id !== null
                && ($order->manifestItems()->whereNull('released_at')->exists()
                    || (new ManifestService)->currentHubId($order) !== (int) $courier->rider?->station_hub_id)) {
                return null;
            }

            $order->forceFill([
                'delivery_courier_id' => $courier->id,
                'destination_area_id' => $this->hubs->areaForBarangay($courier->logistics_provider_id, $order->buyerBarangayCode(), lock: true)?->id,
                'needs_reassignment' => false,
                'reassignment_reason' => null,
                'reassignment_flagged_at' => null,
            ])->save();
            $courier->forceFill(['is_available' => false])->save();

            $assignment = $this->recordAssignment($order, $courier, 'delivery', $provider);
            if (filled($reason)) {
                $assignment->forceFill(['notes' => json_encode(['reason' => trim($reason), 'previous_courier_id' => $replacedCourier?->id, 'courier_id' => $courier->id, 'rider_id' => $courier->rider_id], JSON_THROW_ON_ERROR)])->save();
            }

            if (! $reserveOnly) {
                $this->status->transition(
                    $order,
                    OrderStatus::AssignedToRider,
                    note: "Delivery courier {$courier->name} assigned.",
                    changedBy: 'system',
                    actor: OrderActor::Logistics,
                    actorId: $courier->logistics_provider_id,
                    source: 'web',
                );
            }

            return $courier;
        }, 3);

        // Dispatch only after commit. Notify the new rider, and — when a
        // failed delivery was replaced — the rider who lost the task.
        if ($courier !== null && $assignment !== null && ! $reserveOnly) {
            $order = $sellerOrder->fresh();

            $this->riderNotifier->send(
                $courier->rider,
                new DeliveryAssigned($order, $assignment->id, $rescheduled),
            );

            if ($replacedCourier !== null && (int) $replacedCourier->id !== (int) $courier->id) {
                $this->riderNotifier->send(
                    $replacedCourier->rider,
                    new AssignmentReassigned($order, 'delivery', $assignment->id),
                );
            }
        }

        return $courier;
    }

    /** Replace an active leg without changing custody status or routing snapshots. */
    public function reassign(SellerOrder $sellerOrder, LogisticsProvider $provider, string $role, ?int $preferredCourierId = null, ?string $reason = null, ?int $expectedCourierId = null): ?Courier
    {
        if (! in_array($role, ['pickup', 'delivery'], true)) {
            throw new \InvalidArgumentException('Unknown assignment leg.');
        }
        Gate::forUser($provider)->authorize('advance-shipment', $sellerOrder);
        if (blank($reason) || mb_strlen(trim($reason)) > 500) {
            throw ValidationException::withMessages(['reason' => 'Provide a manual reassignment reason of 500 characters or fewer.']);
        }
        $column = $role === 'pickup' ? 'courier_id' : 'delivery_courier_id';
        $expectedCourierId ??= (int) $sellerOrder->{$column};
        $statuses = $role === 'pickup'
            ? [OrderStatus::ReadyForPickup, OrderStatus::PickedUp]
            : [OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery];
        $assignment = null;
        $previous = null;
        $courier = DB::transaction(function () use ($sellerOrder, $provider, $role, $preferredCourierId, $column, $statuses, &$assignment, &$previous, $reason, $expectedCourierId): ?Courier {
            $this->hubs->lockProvider($provider->id);
            $order = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->first();
            if ($order !== null) {
                ParcelIncidentService::assertOperationsAllowed($order);
            }
            if ($order === null || ! in_array($order->status, $statuses, true)
                || $order->{$column} === null || (int) $order->{$column} !== $expectedCourierId) {
                return null;
            }
            if ($role === 'pickup' && $order->handover_at !== null) {
                return null;
            }
            if ($role === 'delivery' && ! $this->deliveryReadyAtStation($order, (int) $provider->id)) {
                return null;
            }
            Gate::forUser($provider)->authorize('advance-shipment', $order);
            if ($role === 'delivery' && (int) $order->delivery_attempts >= SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS) {
                return null;
            }
            $previous = Courier::query()->whereKey($order->{$column})->lockForUpdate()->first();
            if ($previous === null || (int) $previous->logistics_provider_id !== (int) $provider->id
                || ($order->currentCustodyProviderId() !== null && $order->currentCustodyProviderId() !== (int) $provider->id)
                || ($order->currentCustodyProviderId() === null && $order->courier_id !== null && (int) $order->courier?->logistics_provider_id !== (int) $provider->id)) {
                return null;
            }
            $stationId = $role === 'pickup' ? $order->origin_station_hub_id : $order->destinationArea?->station_hub_id;
            // A replacement is not permission to guess a legacy pickup origin.
            if ($role === 'pickup' && $stationId === null) {
                return null;
            }
            $courier = $this->candidateFor(
                $order,
                $role === 'pickup' ? $order->sellerBarangayCode() : $order->buyerBarangayCode(),
                $provider,
                $preferredCourierId,
                providerIds: [$provider->id],
                stationHubId: $stationId !== null ? (int) $stationId : null,
                excludedCourierId: $previous->id,
            );
            if ($courier === null) {
                $order->flagForReassignment('Manual '.$role.' reassignment unavailable: '.trim($reason));

                return null;
            }
            $order->forceFill([
                $column => $courier->id,
                'needs_reassignment' => false,
                'reassignment_reason' => null,
                'reassignment_flagged_at' => null,
            ])->save();
            $courier->forceFill(['is_available' => false])->save();
            $hasOtherTasks = SellerOrder::query()->where(function ($query) use ($previous): void {
                $query->where(function ($pickup) use ($previous): void {
                    $pickup->where('courier_id', $previous->id)->whereIn('status', [OrderStatus::ReadyForPickup, OrderStatus::PickedUp]);
                })->orWhere(function ($delivery) use ($previous): void {
                    $delivery->where('delivery_courier_id', $previous->id)->whereIn('status', [OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery]);
                });
            })->exists();
            if (! $hasOtherTasks && $previous->isEligibleForAssignment()) {
                $previous->forceFill(['is_available' => true])->save();
            }
            $assignment = $this->recordAssignment($order, $courier, $role, $provider);
            $assignment->forceFill(['notes' => json_encode([
                'reason' => trim($reason), 'previous_courier_id' => $previous->id,
                'courier_id' => $courier->id, 'previous_rider_id' => $previous->rider_id,
                'rider_id' => $courier->rider_id,
            ], JSON_THROW_ON_ERROR)])->save();

            return $courier;
        }, 3);
        if ($courier !== null && $assignment !== null) {
            $order = $sellerOrder->fresh();
            if ($role === 'pickup') {
                $this->riderNotifier->send($previous?->rider, new AssignmentReassigned($order, $role, $assignment->id));
            } elseif ($order->status !== OrderStatus::Sorted) {
                $this->riderNotifier->send($courier->rider, new DeliveryAssigned($order, $assignment->id));
                $this->riderNotifier->send($previous?->rider, new AssignmentReassigned($order, $role, $assignment->id));
            }
        }

        return $courier;
    }

    /**
     * Reserve a PICKUP rider WITHOUT advancing the parcel.
     *
     * This is the Logistics half of the rider-operated pickup flow: Logistics
     * assigns the rider, then the Rider confirms the physical collection from
     * their own panel (which is the hop that mints the tracking number and
     * records handover_at). The parcel stays ReadyForPickup until then, so it
     * is still listed for Logistics until the rider does, and Logistics
     * still has to confirm receipt at the origin Station Hub before the parcel
     * advances to sorting.
     *
     * Selection follows the same rules as assignPickup(): the rider's Station
     * Hub must cover the SELLER's barangay, and the courier must be
     * eligible, free, heavy enough for the parcel and owned by the acting
     * provider. The seller_order row is locked so two dispatchers cannot
     * reserve the same parcel twice.
     *
     * If `reassign` is true, an already-assigned parcel can be reassigned to a
     * new rider; the previous courier is released back to available.
     */
    public function reservePickup(SellerOrder $sellerOrder, ?LogisticsProvider $provider = null, ?int $preferredCourierId = null, bool $reassign = false, ?string $reason = null, ?int $expectedCourierId = null, bool $notifyRider = false): ?Courier
    {
        if ($reassign && $sellerOrder->courier_id !== null) {
            $provider ??= $sellerOrder->courier?->provider;

            return $provider !== null ? $this->reassign($sellerOrder, $provider, 'pickup', $preferredCourierId, $reason, $expectedCourierId) : null;
        }
        $assignment = null;
        $providerIds = $this->assignmentProviderIds($provider, $sellerOrder->sellerBarangayCode());

        $courier = DB::transaction(function () use ($sellerOrder, $provider, $preferredCourierId, &$assignment, $providerIds, $reason): ?Courier {
            foreach ($providerIds as $providerId) {
                $this->hubs->lockProvider($providerId);
            }
            $order = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->lockForUpdate()
                ->first();
            if ($order !== null) {
                ParcelIncidentService::assertOperationsAllowed($order);
            }

            if ($order === null) {
                return null;
            }

            if ($order->status !== OrderStatus::ReadyForPickup) {
                return null;
            }

            if ($provider !== null && (($order->currentCustodyProviderId() !== null
                && $order->currentCustodyProviderId() !== (int) $provider->id)
                || ($order->currentCustodyProviderId() === null && $order->courier_id !== null
                    && (int) $order->courier?->logistics_provider_id !== (int) $provider->id))) {
                return null;
            }

            // Existing assignments must use the replacement path above.
            if ($order->courier_id !== null) {
                return null;
            }

            $courier = $this->candidateFor(
                $order,
                $order->sellerBarangayCode(),
                $provider,
                $preferredCourierId,
                providerIds: $providerIds,
                stationHubId: $order->origin_station_hub_id !== null ? (int) $order->origin_station_hub_id : null,
            );

            if ($courier === null) {
                return null;
            }

            $this->assertCourierBelongsToProvider($courier, $provider);

            $order->forceFill([
                'courier_id' => $courier->id,
                'needs_reassignment' => false,
                'reassignment_reason' => null,
                'reassignment_flagged_at' => null,
            ])->save();
            $courier->forceFill(['is_available' => false])->save();

            if ($order->pickup_address_snapshot === null) {
                $order->forceFill(['pickup_address_snapshot' => [
                    'full' => $order->pickupAddress(), 'barangay_code' => $order->sellerBarangayCode(),
                    'province_code' => $order->seller?->province_code,
                ]])->save();
            }
            // Freeze origin Station Hub from coverage at routing time (never overwrite).
            $this->hubs->assignOriginStationIfMissing($order, (int) $courier->logistics_provider_id);

            $assignment = $this->recordAssignment($order, $courier, 'pickup', $provider);
            if (filled($reason)) {
                $assignment->forceFill(['notes' => json_encode(['reason' => trim($reason), 'courier_id' => $courier->id, 'rider_id' => $courier->rider_id], JSON_THROW_ON_ERROR)])->save();
            }

            return $courier;
        }, 3);

        if ($courier !== null && $assignment !== null && $notifyRider) {
            $this->riderNotifier->send(
                $courier->rider,
                new PickupAssigned($sellerOrder->fresh(), $assignment->id),
            );
        }

        return $courier;
    }

    /**
     * Confirm the dispatch of a reserved PICKUP Rider. This is the only step
     * that tells the Rider about the task: reserving a Rider stays silent.
     *
     * The notification is keyed to the assignment row, so repeated clicks
     * cannot duplicate it, while a reassignment creates a new row that can be
     * confirmed exactly once for the newly selected Rider.
     *
     * @return array{assignment: DeliveryAssignment, notified: bool}|null null when
     *                                                                    the parcel is not awaiting a confirmed pickup dispatch for this provider
     */
    public function confirmPickupDispatch(SellerOrder $parcel, LogisticsProvider $actor): ?array
    {
        Gate::forUser($actor)->authorize('advance-shipment', $parcel);

        $confirmed = DB::transaction(function () use ($parcel, $actor): ?array {
            $current = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->first();
            if ($current === null) {
                return null;
            }
            ParcelIncidentService::assertOperationsAllowed($current);

            $awaitingDispatch = in_array($current->status, [OrderStatus::ReadyForPickup, OrderStatus::PickedUp], true)
                && $current->handover_at === null;
            if (! $awaitingDispatch || $current->courier_id === null || $current->needs_reassignment) {
                return null;
            }
            if ($current->currentCustodyProviderId() !== null && (int) $current->currentCustodyProviderId() !== (int) $actor->id) {
                return null;
            }
            $courier = Courier::query()->find($current->courier_id);
            if ($courier === null || (int) $courier->logistics_provider_id !== (int) $actor->id
                || ! $courier->isEligibleForAssignment()) {
                return null;
            }
            $assignment = $current->deliveryAssignments()
                ->where('role', 'pickup')
                ->where('courier_id', $courier->id)
                ->latest('id')
                ->first();
            if ($assignment === null) {
                return null;
            }

            $notes = json_decode((string) $assignment->notes, true);
            $notes = is_array($notes) ? $notes : [];
            $alreadyConfirmed = filled($notes['dispatch_confirmed_at'] ?? null);
            if (! $alreadyConfirmed) {
                $notes['dispatch_confirmed_at'] = now()->toIso8601String();
                $assignment->forceFill(['notes' => json_encode($notes, JSON_THROW_ON_ERROR)])->save();
            }

            return ['order' => $current, 'courier' => $courier, 'assignment' => $assignment, 'already_confirmed' => $alreadyConfirmed];
        }, 3);

        if ($confirmed === null) {
            return null;
        }

        if ($confirmed['already_confirmed']) {
            return ['assignment' => $confirmed['assignment'], 'notified' => false];
        }

        $notified = $this->riderNotifier->send(
            $confirmed['courier']->rider,
            new PickupAssigned($confirmed['order'], $confirmed['assignment']->id),
        );

        return ['assignment' => $confirmed['assignment'], 'notified' => $notified];
    }

    /**
     * Release a courier back to available when their run is done.
     */
    public function release(Courier $courier): void
    {
        $courier->forceFill(['is_available' => true])->save();
    }

    /**
     * Public coverage check: does this courier's Station Hub cover the given
     * municipality? Code-first, with the municipality-name fallback used by
     * legacy coverage rows.
     */
    public function coversMunicipality(Courier $courier, ?string $municipalityCode, ?string $municipality): bool
    {
        $rider = $courier->relationLoaded('rider')
            ? $courier->rider
            : $courier->rider()->with('station')->first();

        if ($rider === null || ! $rider->isEligibleForAssignment()) {
            return false;
        }

        $areas = $rider->station->areas()->where('is_active', true);

        if (filled($municipalityCode) && (clone $areas)->where('city_municipality_code', $municipalityCode)->exists()) {
            return true;
        }

        return filled($municipality)
            && (clone $areas)->whereRaw('LOWER(municipality) = ?', [mb_strtolower($municipality)])->exists();
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
    private function recordAssignment(SellerOrder $order, Courier $courier, string $role, ?LogisticsProvider $provider): DeliveryAssignment
    {
        return DeliveryAssignment::query()->create([
            'seller_order_id' => $order->id,
            'courier_id' => $courier->id,
            'role' => $role,
            'assigned_by_type' => $provider !== null ? 'logistics' : 'system',
            'assigned_by_id' => $provider?->getKey(),
        ]);
    }

    public function coversBarangay(Courier $courier, ?string $code): bool
    {
        $area = $this->hubs->areaForBarangay($courier->logistics_provider_id, $code);

        return $area !== null && $courier->isEligibleForAssignment()
            && (int) $courier->rider?->station_hub_id === (int) $area->station_hub_id;
    }

    private function assignmentProviderIds(?LogisticsProvider $provider, ?string $code): array
    {
        if ($provider !== null) {
            return [$provider->id];
        }
        if (blank($code) || ! $this->hubs->coverageSchemaAvailable()) {
            return [];
        }

        // Discovery is only a hint. Every candidate is revalidated with current
        // locking reads after the provider locks; stale discoveries fail closed.
        return Area::query()->where('barangay_code', $code)->where('is_active', true)
            ->orderBy('logistics_provider_id')->pluck('logistics_provider_id')->unique()->all();
    }

    private function candidateFor(
        SellerOrder $sellerOrder,
        ?string $barangayCode,
        ?LogisticsProvider $provider = null,
        ?int $preferredCourierId = null,
        ?int $areaId = null,
        array $providerIds = [],
        ?int $stationHubId = null,
        ?int $excludedCourierId = null,
    ): ?Courier {
        if ($sellerOrder->hasActiveReturn() || $sellerOrder->parcelHandoffs()->whereIn('status', ParcelHandoffService::ACTIVE_STATUSES)->exists()
            || blank($barangayCode) || ! $this->hubs->coverageSchemaAvailable()) {
            return null;
        }
        foreach ($providerIds as $providerId) {
            if ($sellerOrder->currentCustodyProviderId() !== null && $sellerOrder->currentCustodyProviderId() !== (int) $providerId) {
                continue;
            }
            $this->hubs->lockProvider($providerId);
            $owner = LogisticsProvider::query()->findOrFail($providerId);
            if (! $owner->isApproved() || ! $owner->hasVerifiedProvince() || $owner->archived_at !== null) {
                continue;
            }
            $area = $this->hubs->areaForBarangay($providerId, $barangayCode, lock: true);
            if ($area === null || ($stationHubId !== null && (int) $area->station_hub_id !== $stationHubId)) {
                continue;
            }
            if ($areaId !== null && (int) $area->id !== $areaId) {
                $hint = Area::query()->whereKey($areaId)->lockForUpdate()->first();
                if ($hint === null || (int) $hint->logistics_provider_id !== (int) $providerId
                    || filled($hint->barangay_code) || (int) $hint->station_hub_id !== (int) $area->station_hub_id
                    || (filled($hint->city_municipality_code) && $hint->city_municipality_code !== $area->city_municipality_code)) {
                    continue;
                }
            }
            $parcelWeight = $sellerOrder->parcelWeightGrams();
            $candidates = Courier::query()->eligibleForAssignment()
                ->where('couriers.logistics_provider_id', $providerId)
                // Weight, not the is_available busy flag or a one-parcel
                // count, is the assignment constraint.
                ->withWorkload()
                ->withActiveWeight()
                ->when($excludedCourierId !== null, fn ($q) => $q->whereKeyNot($excludedCourierId))
                ->where('couriers.max_weight_grams', '>=', $parcelWeight)
                ->where(function ($q) use ($area, $barangayCode, $providerId): void {
                    $q->whereDoesntHave('areas')->orWhereHas('areas', fn ($assigned) => $assigned
                        ->where('logistics_provider_id', $providerId)
                        ->where('station_hub_id', $area->station_hub_id)
                        ->where('is_active', true)
                        ->where(fn ($coverage) => $coverage->whereNull('barangay_code')->orWhere('barangay_code', $barangayCode)));
                })
                ->whereHas('rider', fn ($q) => $q->where('station_hub_id', $area->station_hub_id)
                    ->where('logistics_provider_id', $providerId))
                ->when($preferredCourierId !== null, fn ($q) => $q->whereKey($preferredCourierId))
                ->orderByRaw('(active_pickups_count + active_deliveries_count) asc')->orderBy('couriers.rider_id')->orderBy('couriers.id')
                ->lockForUpdate()->get();
            foreach ($candidates as $courier) {
                // Eligibility is cumulative: everything the Rider is already
                // carrying plus this parcel must fit within their weight limit.
                if ((int) $courier->active_parcel_weight_grams + $parcelWeight > (int) $courier->max_weight_grams) {
                    continue;
                }
                $rider = Rider::query()->whereKey($courier->rider_id)->lockForUpdate()->first();
                if ($rider === null || ! $rider->isApprovedOrActive() || $rider->email_verified_at === null
                    || (int) $rider->logistics_provider_id !== (int) $providerId
                    || (int) $rider->station_hub_id !== (int) $area->station_hub_id) {
                    continue;
                }
                $courier->setRelation('rider', $rider);

                return $courier;
            }
        }

        return null;
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
