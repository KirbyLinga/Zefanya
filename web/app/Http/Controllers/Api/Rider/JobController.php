<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Resources\Mobile\RiderParcelResource;
use App\Models\Rider\RiderEarning;
use App\Models\Shared\SellerOrder;
use App\Services\Rider\RiderAvailabilityService;
use App\Services\Rider\RiderCashService;
use App\Services\Rider\RiderEarningService;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $pickups = $operations->pickupAssignments($rider)->load(self::RELATIONS);
        $deliveries = $operations->deliveryAssignments($rider)->load(self::RELATIONS);

        return $this->success('Assigned jobs retrieved.', [
            'online' => (bool) $rider->fresh()->is_online,
            'winding_down' => $operations->isWindingDown($rider),
            'pickups' => $pickups->map(fn (SellerOrder $p) => (new RiderParcelResource($p))->withLeg('pickup')->resolve($request))->values(),
            'deliveries' => $deliveries->map(fn (SellerOrder $p) => (new RiderParcelResource($p))->withLeg('delivery')->resolve($request))->values(),
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

        return $this->success('Earnings retrieved.', [
            'summary' => $earnings->summaryFor($courier),
            'cod' => [
                'holding_minor' => $cashSummary['holdingMinor'],
                'holding_count' => $cashSummary['holdingCount'],
                'overdue_count' => $cashSummary['overdueCount'],
                'handed_over_minor' => $cashSummary['handedOverMinor'],
            ],
            'recent' => $earnings->recentFor($courier, 20)->map(fn (RiderEarning $e): array => [
                'id' => $e->id,
                'job' => $e->job,
                'amount_minor' => (int) $e->amount_minor,
                'tracking_number' => $e->sellerOrder?->tracking_number,
                'earned_at' => $e->earned_at?->toIso8601String(),
                'paid' => $e->rider_payout_id !== null,
                'payout_reference' => $e->payout?->reference,
            ])->values(),
        ]);
    }
}
