<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelRoute;
use App\Models\Logistics\ParcelRouteRereoute;
use App\Models\Logistics\TransportConnection;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tracks and manages route execution: current location, next destination,
 * leg progression, and rerouting when connections become unavailable.
 *
 * Provides operational visibility into multi-leg parcel movement without
 * modifying custody or status — those are handled by existing Manifest
 * and ParcelHandoff services.
 */
class RouteExecutionService
{
    private RoutePlannerService $planner;

    public function __construct(RoutePlannerService $planner)
    {
        $this->planner = $planner;
    }

    /** The accountable origin remains on transfer records; an in-transit parcel has no verified current hub. */
    public function currentLocation(ParcelRoute $route): ?LogisticsHub
    {
        $order = $route->sellerOrder()->first();
        if (! $order || $this->isInTransit($route)) {
            return null;
        }

        return app(ParcelLegService::class)->verifiedLocation($order);
    }

    public function isInTransit(ParcelRoute $route): bool
    {
        $order = $route->sellerOrder()->first();
        if (! $order) {
            return false;
        }

        return $order->manifestItems()->whereNull('received_at')->whereNull('released_at')
            ->whereHas('manifest', fn ($query) => $query->where('status', ManifestStatus::Dispatched->value))
            ->exists() || $order->parcelHandoffs()->where('status', ParcelHandoffStatus::InTransit->value)->exists();
    }

    /** The current index points to the next outstanding transfer, including the first departure. */
    public function nextDestination(ParcelRoute $route): ?LogisticsHub
    {
        $route = $route->exists ? $route->fresh() : $route;
        if ($route->isCompleted()) {
            return null;
        }
        $hubId = $route->currentLegDestinationHubId();

        return $hubId ? LogisticsHub::query()->find($hubId) : null;
    }

    public function currentTransportMode(ParcelRoute $route): ?string
    {
        $route = $route->exists ? $route->fresh() : $route;

        return $route->isCompleted() ? null : $route->currentLegTransportMode();
    }

    public function isAtFinalDestination($routeOrOrder): bool
    {
        $route = $routeOrOrder instanceof ParcelRoute ? $routeOrOrder : $routeOrOrder->parcelRoute()->first();
        $route = $route?->exists ? $route->fresh() : $route;

        return $route !== null && $route->isCompleted()
            && (int) $this->currentLocation($route)?->id === (int) $route->destination_hub_id;
    }

    /**
     * Why this parcel cannot be sorted yet. Sorting belongs to the final
     * destination hub, so a saved route must be finished first. Parcels with no
     * saved route, and physical returns, keep their existing behaviour.
     */
    public function sortingBlockedReason(SellerOrder $order): ?string
    {
        $route = $order->parcelRoute()->first();

        if ($route === null || $order->hasActiveReturn() || $this->isAtFinalDestination($route)) {
            return null;
        }

        $next = $this->nextDestination($route);

        return $next !== null
            ? "Sorting happens at the final destination hub. Transfer this parcel to {$next->name} first."
            : 'Sorting happens at the final destination hub. This parcel has not been verified there yet.';
    }

    /** Public tracking facts; planned hubs never stand in for physical receipt. */
    public function trackingSummary(SellerOrder $order): ?array
    {
        $order->loadMissing(['parcelRoute.originHub', 'parcelRoute.destinationHub', 'custodyProvider',
            'manifestItems.manifest.originHub', 'manifestItems.manifest.destinationHub',
            'parcelHandoffs.sendingHub', 'parcelHandoffs.receivingHub', 'statusHistories', 'parcelReturn']);
        $route = $order->parcelRoute;
        $held = ParcelIncidentService::movementBlockers($order)->exists();
        $status = $order->getRawOriginal('status');
        $lastHub = app(ParcelLegService::class)->verifiedLocation($order);
        $inTransit = $route !== null && $this->isInTransit($route);
        $return = $order->parcelReturn;
        $location = match (true) {
            $return?->status === ParcelReturnStatus::Completed => 'Returned to seller',
            $return?->awaitingSellerDecision(), $return?->awaitingBuyerCollection() => 'With buyer while return is arranged',
            in_array($return?->status, [ParcelReturnStatus::AtOrigin, ParcelReturnStatus::AwaitingSeller], true) => $lastHub?->name ?? 'At origin hub awaiting seller receipt',
            $return?->status === ParcelReturnStatus::InTransit => $inTransit ? 'Return in transit between hubs' : ($lastHub?->name ?? 'Return custody awaiting verification'),
            in_array($status, ['DELIVERED', 'COMPLETED'], true) => 'Delivered to buyer',
            $status === 'OUT_FOR_DELIVERY' => 'With delivery rider',
            $inTransit => 'In transit between hubs',
            $status === 'PICKED_UP' => 'With pickup rider',
            default => $lastHub?->name ?? 'Physical hub not yet verified',
        };
        $receipts = [];
        $scans = [];
        foreach ($order->statusHistories as $history) {
            if (! Str::endsWith((string) $history->note, [
                ParcelHandoffVerification::noteSuffix(ParcelHandoffVerification::METHOD_CAMERA),
                ParcelHandoffVerification::noteSuffix(ParcelHandoffVerification::METHOD_MANUAL),
            ])) {
                continue;
            }
            if ($history->to_status === OrderStatus::AtSortingCenter && $history->actor_type === 'logistics'
                && $order->handover_at !== null) {
                $scans[] = ['label' => 'Parcel received at a hub', 'at' => $history->created_at];
            } elseif ($history->to_status === OrderStatus::PickedUp && $history->actor_type === 'rider') {
                $scans[] = ['label' => 'Parcel collected by pickup rider', 'at' => $history->created_at];
            } elseif ($history->to_status === OrderStatus::OutForDelivery && $history->actor_type === 'rider') {
                $scans[] = ['label' => 'Delivery run started', 'at' => $history->created_at];
            } elseif ($history->to_status === OrderStatus::DeliveryFailed && $history->actor_type === 'rider') {
                $scans[] = ['label' => 'Delivery attempt recorded', 'at' => $history->created_at];
            } elseif ($history->to_status === OrderStatus::Delivered && $history->actor_type === 'rider') {
                $scans[] = ['label' => 'Delivery confirmed', 'at' => $history->created_at];
            }
        }
        foreach ($order->manifestItems as $item) {
            if ($item->verified_for_dispatch_at !== null) {
                $scans[] = ['label' => 'Scanned at '.($item->manifest?->originHub?->name ?? 'origin hub'), 'at' => $item->verified_for_dispatch_at];
            }
            if ($item->received_at !== null) {
                $scans[] = ['label' => 'Received at '.($item->manifest?->destinationHub?->name ?? 'receiving hub'), 'at' => $item->received_at];
                $receipts[] = ['hub' => $item->manifest?->destinationHub?->name ?? 'Recorded receiving hub', 'at' => $item->received_at];
            }
        }
        foreach ($order->parcelHandoffs as $handoff) {
            if ($handoff->dispatched_at !== null) {
                $scans[] = ['label' => 'Dispatched from '.($handoff->sendingHub?->name ?? 'sending hub'), 'at' => $handoff->dispatched_at];
            }
            if ($handoff->received_at !== null) {
                $scans[] = ['label' => 'Received at '.($handoff->receivingHub?->name ?? 'receiving hub'), 'at' => $handoff->received_at];
                $receipts[] = ['hub' => $handoff->receivingHub?->name ?? 'Recorded receiving hub', 'at' => $handoff->received_at];
            }
        }

        $nextDestination = $route !== null ? $this->nextDestination($route)?->name : null;

        return [
            'last_scan' => collect($scans)->sortBy('at')->last(),
            'next_step' => $this->trackingNextStep($order, $held, $inTransit, $nextDestination),
            'location' => $location, 'last_verified_hub' => $lastHub?->name,
            'custody_provider' => $order->custodyProvider?->business_name,
            'origin' => $route?->originHub?->name, 'destination' => $route?->destinationHub?->name,
            'leg' => $route === null ? 'No saved route' : ($route->isCompleted() ? 'Transport route completed'
                : ($route->totalLegs() === 0 ? 'Same-station receipt pending' : 'Leg '.($route->current_leg_index + 1).' of '.$route->totalLegs())),
            'next_destination' => $nextDestination,
            'mode' => $route !== null ? $this->currentTransportMode($route) : null,
            'in_transit' => $inTransit || in_array($status, ['PICKED_UP', 'OUT_FOR_DELIVERY'], true),
            'held' => $held, 'receipts' => collect($receipts)->sortBy('at')->values()->all(),
        ];
    }

    private function trackingNextStep(SellerOrder $order, bool $held, bool $inTransit, ?string $nextDestination): string
    {
        if ($held) {
            return 'Logistics is resolving a delivery exception before the parcel can move.';
        }
        $return = $order->parcelReturn;
        if ($return !== null && $return->status !== ParcelReturnStatus::Rejected) {
            return match ($return->status) {
                ParcelReturnStatus::InDispute => 'Awaiting Admin review of the return.',
                ParcelReturnStatus::Completed => 'The seller has received the returned parcel.',
                ParcelReturnStatus::AtOrigin, ParcelReturnStatus::AwaitingSeller => 'Awaiting the seller to confirm physical receipt.',
                ParcelReturnStatus::InTransit => 'The returned parcel is travelling back to the seller.',
                default => $return->awaitingSellerDecision() ? 'Awaiting the seller to review the return request.'
                    : ($return->awaitingBuyerCollection() ? 'Awaiting pickup of the return from the buyer.' : 'Awaiting reverse transport to the seller.'),
            };
        }

        return match ($order->status) {
            OrderStatus::Placed => 'Awaiting seller confirmation.',
            OrderStatus::Confirmed, OrderStatus::Preparing => 'The seller is preparing your parcel for pickup.',
            OrderStatus::ReadyForPickup => 'Awaiting pickup from the seller.',
            OrderStatus::PickedUp => 'Awaiting a verified receiving scan at the origin hub.',
            OrderStatus::OutForDelivery => 'The delivery rider is on the way to your address.',
            OrderStatus::Delivered => 'Check your parcel, then confirm receipt.',
            OrderStatus::Completed => 'No further delivery steps. This order is complete.',
            OrderStatus::Cancelled => 'No further delivery steps. This shipment was cancelled.',
            OrderStatus::Returned => 'This shipment was returned to the seller.',
            OrderStatus::DeliveryFailed => (int) $order->delivery_attempts >= SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS
                ? 'Logistics will arrange return to the seller.' : 'Awaiting Logistics to arrange the final delivery attempt or a return.',
            OrderStatus::AssignedToRider => 'Awaiting the delivery rider to start the delivery.',
            default => $nextDestination !== null
                ? ($inTransit ? 'Awaiting a verified receiving scan at ' : 'Awaiting dispatch to ').$nextDestination.'.'
                : ($order->status === OrderStatus::Sorted ? 'Awaiting assignment to a delivery rider.' : 'Awaiting sorting for delivery.'),
        };
    }

    /**
     * Advance to next leg (after verified receipt at current destination).
     *
     * @throws ValidationException if not ready to advance
     */
    public function advanceToNextLeg(SellerOrder $order): void
    {
        $legService = app(ParcelLegService::class);
        $legService->advanceToNextLeg($order);
    }

    /**
     * Preview an authorized alternative route from the last verified physical
     * location. Nothing is persisted and no custody or status is changed.
     *
     * @return array{verified_hub: LogisticsHub, destination: LogisticsHub, previous_path: array, new_path: array, completed_legs: array, description: string}
     *
     * @throws ValidationException when no safe alternative exists
     */
    public function previewReroute(SellerOrder $order, ?LogisticsProvider $actorProvider = null): array
    {
        return DB::transaction(function () use ($order, $actorProvider): array {
            $order = SellerOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $route = ParcelRoute::query()->where('seller_order_id', $order->id)->lockForUpdate()->first();

            return $this->buildReroutePlan($order, $route, $actorProvider);
        });
    }

    /**
     * Reroute from the last verified physical location due to an unavailable
     * connection, wrong hub, or failed transfer.
     *
     * Only uncompleted future legs are replaced. Completed legs, custody and
     * scan history are preserved and the old/new paths are frozen in the
     * reroute audit row. The route row is updated in place, atomically.
     *
     * @throws ValidationException if rerouting fails
     */
    public function rerouteFromCurrent(
        SellerOrder $order,
        string $reason = 'manual_request',
        ?Incidents $incident = null,
        string $actorType = 'admin',
        ?int $actorId = null,
    ): ParcelRoute {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Provide a reroute reason of 1–1000 characters.']);
        }

        return DB::transaction(function () use ($order, $reason, $incident, $actorType, $actorId): ParcelRoute {
            $order = SellerOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $route = ParcelRoute::query()->where('seller_order_id', $order->id)->lockForUpdate()->first();

            if (! $route) {
                throw ValidationException::withMessages(['route' => 'No active route to reroute from.']);
            }

            if ($incident !== null && ParcelRouteRereoute::query()->where('incident_id', $incident->id)->exists()) {
                throw ValidationException::withMessages(['route' => 'This exception has already been rerouted.']);
            }

            abort_unless($incident !== null && (int) $incident->incident_seller_order_id === (int) $order->id
                && $incident->fresh()->isActive() && $actorId !== null, 403);
            abort_unless(in_array($actorType, ['admin', 'logistics_provider'], true), 403);
            abort_unless(in_array($incident->type, ['missing', 'wrong_hub', 'inactive_connection', 'failed_transfer'], true), 403);
            if ($actorType === 'admin') {
                $admin = Admin::query()->findOrFail($actorId);
                abort_unless(in_array($admin->role, ['super_admin', 'admin', 'moderator'], true), 403);
            }

            $actorProvider = null;
            if ($actorType === 'logistics_provider') {
                $actorProvider = LogisticsProvider::query()->findOrFail($actorId);
                abort_unless($actorProvider->isApproved() && $actorProvider->archived_at === null, 403);
            }

            $plan = $this->buildReroutePlan($order, $route, $actorProvider);

            $route->forceFill([
                'destination_hub_id' => $plan['destination']->id,
                'legs' => $plan['new_path'],
                'current_leg_index' => count($plan['completed_legs']),
                'route_completed_at' => null,
            ])->save();

            ParcelRouteRereoute::create([
                'seller_order_id' => $order->id,
                'original_route_id' => $route->id,
                'new_route_id' => $route->id,
                'reason' => $reason,
                'triggered_by' => 'authorized_exception_resolution',
                'previous_path' => $plan['previous_path'],
                'new_path' => $plan['new_path'],
                'completed_legs' => $plan['completed_legs'],
                'authorized_by_type' => $actorType,
                'authorized_by_id' => $actorId,
                'incident_id' => $incident?->id,
                'rerouted_at' => now(),
            ]);

            return $route->fresh(['originHub', 'destinationHub', 'sellerOrder']);
        });
    }

    /**
     * Build and validate a reroute plan without changing any record.
     *
     * @return array{verified_hub: LogisticsHub, destination: LogisticsHub, previous_path: array, new_path: array, completed_legs: array, description: string}
     */
    private function buildReroutePlan(SellerOrder $order, ?ParcelRoute $route, ?LogisticsProvider $actorProvider): array
    {
        if (! $route) {
            throw ValidationException::withMessages(['route' => 'No active route to reroute from.']);
        }

        if ($route->isCompleted()) {
            throw ValidationException::withMessages(['route' => 'A completed route cannot be rerouted.']);
        }

        // An in-transit parcel has not completed a physical transfer yet. Its
        // current leg must never be rewritten under the moving vehicle.
        if ($this->isInTransit($route)) {
            throw ValidationException::withMessages([
                'route' => 'The parcel is on an active transfer. Resolve the physical transfer or custody first, then reroute.',
            ]);
        }

        $verifiedHub = app(ParcelLegService::class)->verifiedLocation($order);
        if (! $verifiedHub) {
            throw ValidationException::withMessages([
                'route' => 'A verified physical hub receipt is required before rerouting. A planned destination is not proof of location.',
            ]);
        }

        if ($actorProvider !== null) {
            abort_unless((int) $order->currentCustodyProviderId() === (int) $actorProvider->id, 403, 'Only the provider currently holding the parcel can reroute it.');
            abort_unless((int) $verifiedHub->logistics_provider_id === (int) $actorProvider->id, 403, 'You cannot reroute from another provider\'s station.');
        }

        $destination = LogisticsHub::query()->find($route->destination_hub_id);
        if (! $destination) {
            throw ValidationException::withMessages(['route' => 'The saved destination hub no longer exists.']);
        }

        if ((int) $verifiedHub->id === (int) $destination->id) {
            throw ValidationException::withMessages(['route' => 'The parcel is already at its destination hub.']);
        }

        $legs = is_array($route->legs) ? $route->legs : ($route->legs ? json_decode($route->legs, true) : []);
        if ($route->current_leg_index < 0 || $route->current_leg_index >= count($legs)) {
            throw ValidationException::withMessages(['route' => 'The saved route progress is invalid.']);
        }
        $completedCount = $route->current_leg_index + (app(ParcelLegService::class)->isReadyForNextLeg($order) ? 1 : 0);
        $completedLegs = array_slice($legs, 0, $completedCount);
        $completedHubIds = array_merge([(int) $route->origin_hub_id], array_map(fn ($leg) => (int) $leg['hub_id'], $completedLegs));
        $expectedHubId = $completedCount > $route->current_leg_index ? end($completedHubIds)
            : ($route->currentLeg()['origin_hub_id'] ?? end($completedHubIds));
        if ((int) $verifiedHub->id !== (int) $expectedHubId
            && ! Incidents::query()->where('incident_seller_order_id', $order->id)->where('type', 'wrong_hub')->where('status', 'under_review')->exists()) {
            throw ValidationException::withMessages(['route' => 'Off-route custody requires a reviewed wrong-hub recovery receipt.']);
        }

        $provider = LogisticsProvider::query()->find((int) $verifiedHub->logistics_provider_id);
        if (! $provider) {
            throw ValidationException::withMessages(['route' => 'The verified hub has no responsible provider.']);
        }

        $newLegs = $this->planner->findPath($verifiedHub, $destination, $provider);
        if (empty($newLegs)) {
            throw ValidationException::withMessages([
                'route' => 'No active alternative route exists from the last verified hub. Keep the parcel held.',
            ]);
        }

        $newHubIds = array_map(fn ($leg) => (int) ($leg['hub_id'] ?? 0), $newLegs);
        if (array_intersect($newHubIds, $completedHubIds) !== []) {
            throw ValidationException::withMessages([
                'route' => 'The alternative route would revisit a completed hub. Keep the parcel held and review the network.',
            ]);
        }

        if ((int) end($newHubIds) !== (int) $destination->id) {
            throw ValidationException::withMessages(['route' => 'The alternative route does not reach the original destination hub.']);
        }

        // Revalidate every selected connection, including its directional
        // provider link, at execution time rather than trusting planning time.
        foreach ($newLegs as $leg) {
            $connectionId = $leg['transport_connection_id'] ?? null;
            $connection = $connectionId !== null
                ? TransportConnection::query()->lockForUpdate()->find($connectionId)
                : TransportConnection::query()
                    ->where('origin_hub_id', $verifiedHub->id)
                    ->where('destination_hub_id', $leg['hub_id'] ?? 0)
                    ->where('transport_mode', $leg['transport_mode'] ?? '')
                    ->first();

            if (! $connection || ! $connection->isActive()) {
                throw ValidationException::withMessages(['route' => 'A selected transport connection or provider link is no longer active.']);
            }
        }

        if ((int) $verifiedHub->id !== (int) $expectedHubId) {
            // This explicit departure origin is backed by the authorized physical recovery receipt.
            $newLegs[0]['origin_hub_id'] = (int) $verifiedHub->id;
        }

        return [
            'verified_hub' => $verifiedHub,
            'destination' => $destination,
            'previous_path' => $legs,
            'new_path' => array_merge($completedLegs, $newLegs),
            'future_path' => $newLegs,
            'completed_legs' => $completedLegs,
            'description' => $this->planner->describeRoute(new ParcelRoute([
                'origin_hub_id' => $verifiedHub->id,
                'destination_hub_id' => $destination->id,
                'legs' => $newLegs,
            ])),
        ];
    }

    /**
     * Validate that all connections in route are currently available and active.
     *
     * Used to detect when connections or hubs have become unavailable after
     * route was planned, allowing early trigger of rerouting.
     */
    public function validateRouteConnectivity(ParcelRoute $route): bool
    {
        $route = $route->exists ? $route->fresh() : $route;

        return $route->isCompleted() ? $this->isAtFinalDestination($route)
            : $this->planner->isRouteFeasible($route, $route->current_leg_index);
    }

    /**
     * Get human-readable summary of route execution status.
     */
    public function routeSummary($routeOrOrder): string
    {
        // Accept either ParcelRoute or SellerOrder
        $route = $routeOrOrder instanceof ParcelRoute ? $routeOrOrder : $routeOrOrder->parcelRoute;
        if (! $route) {
            return 'No active route.';
        }

        $route = $route->exists ? $route->fresh() : $route;
        $current = $this->currentLocation($route);
        if ($this->isAtFinalDestination($route)) {
            return 'Route completed at '.$current->name.'.';
        }
        $next = $this->nextDestination($route);
        $currentName = $this->isInTransit($route) ? 'In transit' : ($current?->name ?? 'Awaiting verified hub receipt');
        $nextName = $next?->name ?? 'No transfer required';
        $transportMode = $this->currentTransportMode($route) ?? 'N/A';
        $totalLegs = $route->totalLegs();
        $legNum = $totalLegs === 0 ? 0 : $route->current_leg_index + 1;

        return "Leg {$legNum}/{$totalLegs}: {$currentName} -> {$nextName} ({$transportMode})";
    }
}
