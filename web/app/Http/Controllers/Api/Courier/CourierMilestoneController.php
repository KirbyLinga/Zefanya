<?php

namespace App\Http\Controllers\Api\Courier;

use App\Enums\OrderStatus;
use App\Exceptions\DeliveryHandoverException;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use App\Services\OrderDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /api/courier/jobs/{sellerOrder}/milestone
 *
 * Advance a job's pipeline status one step. The courier holds the truth
 * about where the parcel is; this is how they write it to the server so
 * buyers can see it.
 *
 * A DELIVERED transition is delegated to OrderDeliveryService, the only
 * writer of the delivered status. The service verifies the buyer's code or
 * signed token, enforces expiry/single-use/lockout, stores the proof photo and
 * settles COD in the same transaction.
 */
class CourierMilestoneController extends Controller
{
    /**
     * The transitions the courier app may request, keyed by current status.
     */
    private const array ALLOWED_TRANSITIONS = [
        'PLACED' => ['CONFIRMED'],
        'CONFIRMED' => ['PREPARING'],
        'PREPARING' => ['READY_FOR_PICKUP'],
        'READY_FOR_PICKUP' => ['PICKED_UP'],
        'PICKED_UP' => ['AT_SORTING_CENTER'],
        'AT_SORTING_CENTER' => ['SORTED'],
        'SORTED' => ['ASSIGNED_TO_RIDER'],
        'ASSIGNED_TO_RIDER' => ['OUT_FOR_DELIVERY'],
        'OUT_FOR_DELIVERY' => ['DELIVERED', 'DELIVERY_FAILED', 'RETURNED'],
        'DELIVERY_FAILED' => ['OUT_FOR_DELIVERY', 'DELIVERED', 'RETURNED'],
        // Note: DELIVERED, COMPLETED and RETURNED are terminal.
    ];

    /** Statuses that are finished and cannot be advanced. */
    private const array TERMINAL = ['DELIVERED', 'COMPLETED', 'RETURNED'];

    /** The workbook's limit: a fourth attempt is never allowed. */
    private const int MAX_DELIVERY_ATTEMPTS = 3;

    /** The one reason that ends the attempt chain at once (RID-FAIL-003). */
    private const string BUYER_REFUSED = 'Buyer refused';

    public function __construct(private readonly OrderDeliveryService $delivery) {}

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
            'failure_reason' => ['nullable', 'string', 'max:80'],
            'delivery_otp' => ['nullable', 'string', 'max:6'],
            'delivery_token' => ['nullable', 'string', 'max:512'],
            'proof_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'tracking_number' => ['nullable', 'string', 'max:40'],
        ]);

        $target = strtoupper($data['milestone']);

        // Validate the target is a known status.
        if (! in_array($target, array_column(OrderStatus::cases(), 'value'), true)) {
            return response()->json([
                'message' => "Unknown milestone: $target.",
            ], 422);
        }

        if ($target === 'DELIVERED') {
            try {
                $job = $this->delivery->markDelivered(
                    $sellerOrder,
                    $courier,
                    $data,
                    $request->file('proof_photo'),
                    $request->ip(),
                );
            } catch (DeliveryHandoverException $e) {
                return response()->json(['message' => $e->getMessage()], $e->statusCode);
            }

            return response()->json([
                'ok' => true,
                'milestone' => $job->status->value,
                'delivery_attempts' => (int) $job->delivery_attempts,
                'payment_status' => $job->payment?->status?->value,
            ]);
        }

        // Lock the row for the whole decision: two taps on "Complete" must
        // not both read attempt 2 and both write attempt 3, and a COD
        // settlement must not race a retry.
        return DB::transaction(function () use ($sellerOrder, $target, $data): JsonResponse {
            $job = SellerOrder::query()
                ->with('payment')
                ->lockForUpdate()
                ->findOrFail($sellerOrder->id);

            $current = $job->status instanceof OrderStatus
                ? $job->status->value
                : (string) $job->status;

            // Cannot advance a finished job.
            if (in_array($current, self::TERMINAL, true)) {
                return response()->json([
                    'message' => "This job has already finished ($current).",
                ], 422);
            }

            // Validate the transition.
            $allowed = self::ALLOWED_TRANSITIONS[$current] ?? [];
            if (! in_array($target, $allowed, true)) {
                return response()->json([
                    'message' => "Cannot transition from $current to $target. "
                        .($allowed !== []
                            ? 'Expected one of: '.implode(', ', $allowed).'.'
                            : 'No further transitions allowed.'),
                ], 422);
            }

            $reason = trim((string) ($data['failure_reason'] ?? ''));

            if ($target === 'PICKED_UP') {
                $problem = $this->pickupProblem($job, $data['tracking_number'] ?? null);
                if ($problem !== null) {
                    return $this->refuse($problem);
                }
            }

            if ($target === 'DELIVERY_FAILED' || $target === 'RETURNED') {
                $problem = $this->failureProblem($job, $target, $reason);
                if ($problem !== null) {
                    return $this->refuse($problem);
                }

                // The third failed attempt is the last one: the parcel goes
                // back to the seller rather than sitting on an unpayable
                // fourth visit.
                if ($target === 'DELIVERY_FAILED'
                    && (int) $job->delivery_attempts >= self::MAX_DELIVERY_ATTEMPTS) {
                    $target = 'RETURNED';
                }
            }

            $job->forceFill(['status' => OrderStatus::from($target)]);
            $job->save();

            return response()->json([
                'ok' => true,
                'milestone' => $job->status->value,
                'delivery_attempts' => (int) $job->delivery_attempts,
                'payment_status' => $job->payment?->status?->value,
            ]);
        });
    }

    /**
     * Check the typed waybill against the one issued to the parcel.
     */
    private function pickupProblem(SellerOrder $job, ?string $submitted): ?string
    {
        $expected = trim((string) $job->tracking_number);
        if ($expected === '') {
            return null;
        }

        $actual = trim((string) $submitted);
        if ($actual === '') {
            return 'Enter the tracking number printed on the parcel.';
        }

        if (strcasecmp($actual, $expected) !== 0) {
            return 'That tracking number does not match this parcel.';
        }

        return null;
    }

    /**
     * Apply the failure/return rules and return a refusal message, if any.
     */
    private function failureProblem(SellerOrder $job, string $target, string $reason): ?string
    {
        if ($reason === '') {
            return 'A failure reason is required.';
        }

        $attempts = (int) ($job->delivery_attempts ?? 0);
        $job->failure_reason = $reason;

        if ($target === 'RETURNED' && $this->isBuyerRefusal($reason)) {
            return null;
        }

        $nextAttempt = $attempts + 1;
        if ($nextAttempt > self::MAX_DELIVERY_ATTEMPTS) {
            return 'This parcel has already used its three delivery attempts.';
        }

        if ($target === 'RETURNED' && $nextAttempt < self::MAX_DELIVERY_ATTEMPTS) {
            return 'A parcel is returned after three failed attempts, or immediately when the buyer refuses.';
        }

        $job->delivery_attempts = $nextAttempt;

        return null;
    }

    /**
     * Does this response count as a refusal?
     */
    private function isBuyerRefusal(string $reason): bool
    {
        return str_contains(strtolower($reason), strtolower(self::BUYER_REFUSED));
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 422);
    }
}
