<?php

namespace App\Http\Controllers\Api\Rider;

use App\Enums\OrderStatus;
use App\Exceptions\StaleStateException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Requests\Api\Rider\ReportPickupFailureApiRequest;
use App\Http\Resources\Mobile\RiderParcelResource;
use App\Http\Resources\Mobile\RiderPickupStopResource;
use App\Models\Logistics\PickupSession;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\PickupPolicy;
use App\Services\Logistics\PickupSessionService;
use App\Services\Rider\PickupFailureService;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Rider mobile pickup exceptions: a failed pickup attempt and finishing a
 * seller stop. Both delegate to the services the Rider web panel uses
 * (PickupFailureService, PickupSessionService), which own the row locks,
 * the two-attempt limit, cancellation, stock restoration, notifications,
 * session closing and earnings. The app never decides any of it.
 *
 * Errors: 403 from the ownership aborts; 422 for validation; 409 for stale
 * state (a duplicate attempt, a parcel no longer waiting, a refused status
 * transition).
 */
class PickupController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly RiderOperationsService $operations) {}

    /** POST /api/rider/pickups/{sellerOrder}/failed */
    public function reportFailure(
        ReportPickupFailureApiRequest $request,
        SellerOrder $sellerOrder,
        PickupFailureService $failures,
    ): JsonResponse {
        $rider = $request->user();
        $this->operations->assertPickupAssigned($rider, $sellerOrder);

        try {
            $result = $failures->report(
                $rider,
                $sellerOrder,
                $request->validated('failure_reason'),
                $request->validated('failure_notes'),
                source: 'api',
                expectedAttempt: (int) $request->validated('expected_attempt'),
                expectedStatus: OrderStatus::ReadyForPickup,
            );
        } catch (StaleStateException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), ['status' => [$e->getMessage()]], 409);
        }

        $parcel = $result['parcel']->fresh(['order', 'seller', 'payment', 'orderItems']) ?? $result['parcel'];

        return $this->success(
            $result['cancelled']
                ? 'Second failed pickup recorded. The order was cancelled and stock restored.'
                : 'Failed pickup recorded. The seller and logistics were notified; you can retry once.',
            [
                'attempt' => $result['attempt'],
                'attempts_remaining' => max(0, PickupFailureService::MAX_ATTEMPTS - $result['attempt']),
                'cancelled' => $result['cancelled'],
                'parcel' => (new RiderParcelResource($parcel))->withLeg('pickup')->resolve($request),
            ],
        );
    }

    /** POST /api/rider/pickups/stops/{pickupSession}/finish */
    public function finishStop(Request $request, PickupSession $pickupSession, PickupSessionService $sessions): JsonResponse
    {
        abort_unless(PickupPolicy::sessionsAvailable(), 404);
        $courier = $this->operations->operationalCourier($request->user());

        // Locks the session and its parcels, refuses another rider's stop (403),
        // refuses a stop with nothing collected yet (422), and is a no-op for a
        // stop already finished. Uncollected parcels move to a new session with
        // the same rider; no earning is created here.
        $sessions->finish($pickupSession, $courier);

        return $this->success(
            'Stop finished. Any parcel you did not collect stays assigned to you for the next visit.',
            ['stop' => (new RiderPickupStopResource($pickupSession->fresh(['seller'])))->resolve($request)],
        );
    }
}
