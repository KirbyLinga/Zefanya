<?php

namespace App\Http\Controllers\Api\Courier;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PATCH /api/courier/jobs/{sellerOrder}/milestone
 *
 * Advance a job's pipeline status one step. The courier holds the truth
 * about where the parcel is; this is how they write it to the server so
 * buyers can see it.
 *
 * ## Ownership
 *
 * The job must be assigned to the signed-in courier. A token cannot
 * advance another rider's job.
 *
 * ## Allowed transitions
 *
 * Not every transition is valid from every state. The server enforces
 * the allowed set — arriving at a state the pipeline has already passed
 * or jumping forward by more than one step is refused with 422.
 * The client's own state machine is the source of truth for *which*
 * next step to send; this server-side check is the guard against a
 * replayed or stale request.
 *
 * Finished terminal states (DELIVERED, COMPLETED, RETURNED) cannot be
 * advanced further — the job is done.
 *
 * ## Body
 *
 *   { "milestone": "PICKED_UP" }
 *
 * The value is one of the `OrderStatus` enum's string values. The client
 * sends the *target* status, not a delta. This is simpler to validate
 * and impossible to misinterpret: "advance by one" has no meaning across
 * a network where requests can arrive out of order.
 */
class CourierMilestoneController extends Controller
{
    /**
     * The transitions the courier app may request, keyed by current status.
     * Each value is the one allowed next status.
     *
     * Sorted in pipeline order so the allowed-set comment is self-documenting.
     */
    private const array ALLOWED_TRANSITIONS = [
        'PLACED'            => 'CONFIRMED',
        'CONFIRMED'         => 'PREPARING',
        'PREPARING'         => 'READY_FOR_PICKUP',
        'READY_FOR_PICKUP'  => 'PICKED_UP',
        'PICKED_UP'         => 'AT_SORTING_CENTER',
        'AT_SORTING_CENTER' => 'SORTED',
        'SORTED'            => 'ASSIGNED_TO_RIDER',
        'ASSIGNED_TO_RIDER' => 'OUT_FOR_DELIVERY',
        'OUT_FOR_DELIVERY'  => 'DELIVERED',
        // Failure branches
        'DELIVERED'         => 'COMPLETED',
        'DELIVERY_FAILED'   => 'OUT_FOR_DELIVERY', // reschedule
        // Note: RETURNED is terminal — no transition from it.
    ];

    /** Statuses that are finished and cannot be advanced. */
    private const array TERMINAL = ['DELIVERED', 'COMPLETED', 'RETURNED'];

    public function update(Request $request, SellerOrder $sellerOrder): JsonResponse
    {
        $courier = $request->user();

        abort_unless(
            $courier instanceof LogisticsProvider,
            403,
            'This token belongs to a different kind of account.',
        );

        // Ownership: the job must be assigned to this courier.
        abort_unless(
            (int) $sellerOrder->assigned_courier_id === $courier->id,
            403,
            'This job is not assigned to your account.',
        );

        $data = $request->validate([
            'milestone' => ['required', 'string', 'max:30'],
        ]);

        $target = strtoupper($data['milestone']);

        // Validate the target is a known status.
        if (! in_array($target, array_column(OrderStatus::cases(), 'value'), true)) {
            return response()->json([
                'message' => "Unknown milestone: $target.",
            ], 422);
        }

        $current = $sellerOrder->status instanceof OrderStatus
            ? $sellerOrder->status->value
            : (string) $sellerOrder->status;

        // Cannot advance a finished job.
        if (in_array($current, self::TERMINAL, true)) {
            return response()->json([
                'message' => "This job has already finished ($current).",
            ], 422);
        }

        // Validate the transition.
        $allowed = self::ALLOWED_TRANSITIONS[$current] ?? null;
        if ($allowed !== $target) {
            return response()->json([
                'message' => "Cannot transition from $current to $target. "
                    . ($allowed !== null ? "Expected $allowed." : 'No further transitions allowed.'),
            ], 422);
        }

        $sellerOrder->forceFill(['status' => $target])->save();

        return response()->json([
            'ok' => true,
            'milestone' => $sellerOrder->fresh()->status->value,
        ]);
    }
}
