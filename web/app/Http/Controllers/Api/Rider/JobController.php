<?php

namespace App\Http\Controllers\Api\Rider;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Resources\Mobile\RiderParcelResource;
use App\Http\Resources\Mobile\RiderPickupStopResource;
use App\Models\Logistics\PickupSession;
use App\Models\Rider\RiderEarning;
use App\Models\Seller\StoreSale;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\PickupPolicy;
use App\Services\Rider\PickupFailureService;
use App\Services\Rider\RiderAvailabilityService;
use App\Services\Rider\RiderCashService;
use App\Services\Rider\RiderEarningService;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Rider mobile read endpoints and the online/offline switch.
 *
 * Thin bearer-token adapters over the same services the Rider web panel
 * uses (RiderOperationsService, RiderAvailabilityService, RiderEarningService,
 * RiderCashService). Scoping to the rider's own Courier happens inside those
 * services; nothing here widens or recomputes it.
 */
class JobController extends Controller
{
    use ApiResponses;

    private const RELATIONS = ['order', 'seller', 'payment', 'orderItems'];

    /** GET /api/rider/jobs — assigned pickups and deliveries. */
    public function index(Request $request, RiderOperationsService $operations): JsonResponse
    {
        $rider = $request->user();
        $windingDown = $operations->isWindingDown($rider);
        // A wind-down rider may only finish parcels already out for delivery,
        // so (as on the web) no pickup list is offered; deliveryAssignments
        // narrows itself to OUT_FOR_DELIVERY in that state.
        $pickups = $windingDown
            ? new EloquentCollection
            : $operations->pickupAssignments($rider)->load(self::RELATIONS)->loadMax('pickupAttempts', 'attempt_number');
        $deliveries = $operations->deliveryAssignments($rider)->load(self::RELATIONS);

        return $this->success('Assigned jobs retrieved.', [
            'online' => (bool) $rider->fresh()->is_online,
            'winding_down' => $windingDown,
            'pickups' => $pickups->map(fn (SellerOrder $p) => (new RiderParcelResource($p))->withLeg('pickup')->resolve($request))->values(),
            // One entry per seller stop the pickups belong to (collected / remaining
            // counts and earnings come from PickupSessionService::summary).
            'stops' => $this->stops($pickups, $request),
            'pickup_failure' => [
                'max_attempts' => PickupFailureService::MAX_ATTEMPTS,
                'reasons' => collect(DeliveryFailureReason::cases())
                    ->map(fn (DeliveryFailureReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()])
                    ->values(),
            ],
            'deliveries' => $deliveries->map(fn (SellerOrder $p) => (new RiderParcelResource($p))->withLeg('delivery')->resolve($request))->values(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function stops(EloquentCollection $pickups, Request $request): array
    {
        $ids = $pickups->pluck('pickup_session_id')->filter()->unique()->values();
        if ($ids->isEmpty() || ! PickupPolicy::sessionsAvailable()) {
            return [];
        }

        return PickupSession::query()->whereKey($ids->all())->with('seller')->orderBy('id')->get()
            ->map(fn (PickupSession $session): array => (new RiderPickupStopResource($session))->resolve($request))
            ->values()->all();
    }

    /**
     * GET /api/rider/history — the rider's finished deliveries (delivered,
     * completed, failed, returned), newest first, from the same
     * RiderOperationsService::deliveryHistory query as the web history page.
     * Buyer contact details are not included for finished parcels.
     */
    public function history(Request $request, RiderOperationsService $operations): JsonResponse
    {
        $courier = $operations->operationalCourier($request->user());
        $parcels = $operations->deliveryHistory($request->user())->load(['payment', 'orderItems', 'parcelReturn']);
        $credited = RiderEarning::query()
            ->where('courier_id', $courier->getKey())
            ->where('job', RiderEarning::DELIVERY)
            ->whereIn('seller_order_id', $parcels->modelKeys())
            ->pluck('amount_minor', 'seller_order_id');

        return $this->success('Delivery history retrieved.', [
            'history' => $parcels->map(fn (SellerOrder $parcel): array => [
                ...(new RiderParcelResource($parcel))->withLeg('history')->resolve($request),
                'returned' => $parcel->status === OrderStatus::Returned || $parcel->parcelReturn !== null,
                'earning_minor' => $credited->has($parcel->id) ? (int) $credited->get($parcel->id) : null,
                'delivered_at' => $parcel->deliveryProof?->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** POST /api/rider/availability — {online: bool}. */
    public function availability(Request $request, RiderAvailabilityService $availability): JsonResponse
    {
        $data = $request->validate(['online' => ['required', 'boolean']]);
        $result = $availability->setOnline($request->user(), (bool) $data['online']);

        return $this->success($data['online'] ? 'You are online.' : 'You are offline.', [
            'online' => (bool) $request->user()->fresh()->is_online,
            'changed' => (bool) ($result['changed'] ?? false),
            'jobs_flagged_for_reassignment' => (int) ($result['flagged'] ?? 0),
        ]);
    }

    /** GET /api/rider/earnings — server-computed earnings and COD custody. */
    public function earnings(
        Request $request,
        RiderOperationsService $operations,
        RiderEarningService $earnings,
        RiderCashService $cash,
    ): JsonResponse {
        $courier = $operations->operationalCourier($request->user(), allowWindDown: true);
        $cashSummary = $cash->summaryFor($courier);
        $limit = min(max((int) $request->query('limit', 20), 1), 100);

        // Sums of the credited RiderEarning rows only; amounts are never
        // recomputed here. Day and week boundaries use the store timezone, as
        // RiderEarningService does for the month.
        $credited = RiderEarning::query()->where('courier_id', $courier->getKey());
        $now = Carbon::now(StoreSale::TIMEZONE);

        return $this->success('Earnings retrieved.', [
            'summary' => $earnings->summaryFor($courier),
            'totals' => [
                'today_minor' => (int) (clone $credited)->where('earned_at', '>=', $now->copy()->startOfDay()->utc())->sum('amount_minor'),
                'week_minor' => (int) (clone $credited)->where('earned_at', '>=', $now->copy()->startOfWeek()->utc())->sum('amount_minor'),
                'all_time_minor' => (int) (clone $credited)->sum('amount_minor'),
                'jobs' => (clone $credited)->count(),
            ],
            'cod' => [
                'holding_minor' => $cashSummary['holdingMinor'],
                'holding_count' => $cashSummary['holdingCount'],
                'overdue_count' => $cashSummary['overdueCount'],
                'handed_over_minor' => $cashSummary['handedOverMinor'],
            ],
            'recent' => $earnings->recentFor($courier, $limit)->load('sellerOrder.seller:id,business_name')->map(fn (RiderEarning $e): array => [
                'id' => $e->id,
                'job' => $e->job,
                'amount_minor' => (int) $e->amount_minor,
                'seller_order_id' => $e->seller_order_id,
                'tracking_number' => $e->sellerOrder?->tracking_number,
                'seller_name' => $e->sellerOrder?->seller?->business_name,
                'earned_at' => $e->earned_at?->toIso8601String(),
                'paid' => $e->rider_payout_id !== null,
                'payout_reference' => $e->payout?->reference,
            ])->values(),
        ]);
    }
}
