<?php

namespace App\Http\Controllers\Api\Courier;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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
 *   {
 *     "milestone": "DELIVERED",
 *     "delivery_otp": "482913",
 *     "cod_amount": 700.00
 *   }
 *
 * Only `milestone` is required. The other fields are checks the server
 * performs when the job carries the data to check:
 *
 *   - `tracking_number` on PICKED_UP, compared with the one issued to
 *     the parcel. A typed number is the manual form of the workbook's
 *     scan, and the server must verify it rather than trusting the app.
 *   - `failure_reason` on DELIVERY_FAILED and RETURNED. Without a reason
 *     the buyer and Logistics have nothing to act on, so no state changes.
 *   - `delivery_otp` on DELIVERED, checked against a hash. Five wrong
 *     codes lock entry for 15 minutes.
 *   - `cod_amount` on DELIVERED, compared to the exact centavo owed. A
 *     match settles the payment in the same transaction as the handover.
 *
 * The client is never trusted for the attempt count, the OTP, or the
 * amount: it may display all three, but only the values stored here decide
 * whether the parcel changed hands.
 */
class CourierMilestoneController extends Controller
{
    /**
     * The transitions the courier app may request, keyed by current status.
     *
     * A list rather than a single value because the delivery leg branches:
     * a failed attempt may be retried, delivered, or returned immediately
     * when the buyer refuses. Sorted in pipeline order so the graph is
     * readable.
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

    /** Wrong delivery codes before entry is locked (RID-DLV-004). */
    private const int MAX_OTP_ATTEMPTS = 5;

    /** How long a locked code entry stays closed. */
    private const int OTP_LOCK_MINUTES = 15;

    /** The one reason that ends the attempt chain at once (RID-FAIL-003). */
    private const string BUYER_REFUSED = 'Buyer refused';

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

            if ($target === 'DELIVERED') {
                $refusal = $this->handoverProblem($job, $data);
                if ($refusal !== null) {
                    return $refusal;
                }
            }

            $job->forceFill(['status' => OrderStatus::from($target)]);

            if ($target === 'DELIVERED' && $job->delivered_at === null) {
                $job->delivered_at = now();
            }

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
     *
     * A parcel with no issued number (legacy rows) cannot be checked, so it
     * is allowed through rather than blocking a rider on missing data. Once
     * a number exists, blank or wrong input is refused.
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
     *
     * A failed attempt increments the stored count; the third one is the
     * last, so the parcel returns to the seller immediately. A buyer's
     * refusal skips the remaining attempts. A non-refusal RETURNED request
     * is only accepted when it *is* that third failure — otherwise a rider
     * could abandon a parcel after one try.
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
     * Verify the handover: the buyer's code and, for COD, the exact cash.
     *
     * A refusal response is returned rather than thrown so the OTP attempt
     * counter can be persisted before the caller sees the 422 — a wrong
     * code has to count, or the five-attempt lock would never appear.
     */
    private function handoverProblem(SellerOrder $job, array $data): ?JsonResponse
    {
        $otpHash = $job->delivery_otp_hash;

        if ($otpHash !== null) {
            $lockedUntil = $job->delivery_otp_locked_until;

            if ($lockedUntil !== null && now()->lt($lockedUntil)) {
                $minutes = max(
                    1,
                    (int) ceil(now()->diffInSeconds($lockedUntil, true) / 60),
                );

                return response()->json([
                    'message' => "Too many wrong delivery codes. Try again in $minutes minutes.",
                ], 423);
            }

            // The lock has expired: the next entry starts a fresh set.
            if ($lockedUntil !== null) {
                $job->delivery_otp_attempts = 0;
                $job->delivery_otp_locked_until = null;
            }

            $code = (string) ($data['delivery_otp'] ?? '');
            if (preg_match('/^\d{6}$/', $code) !== 1) {
                return $this->refuse('Enter the 6-digit delivery code shown by the buyer.');
            }

            if (! Hash::check($code, $otpHash)) {
                $attempts = (int) ($job->delivery_otp_attempts ?? 0) + 1;
                $job->delivery_otp_attempts = $attempts;

                if ($attempts >= self::MAX_OTP_ATTEMPTS) {
                    $job->delivery_otp_locked_until = now()->addMinutes(self::OTP_LOCK_MINUTES);
                }

                $job->save();

                return $this->refuse('That delivery code is not correct.');
            }

            $job->delivery_otp_attempts = 0;
            $job->delivery_otp_locked_until = null;
        }

        $payment = $job->payment;

        if ($payment === null
            || $payment->method !== PaymentMethod::Cod
            || $payment->status !== PaymentStatus::Pending) {
            return null;
        }

        if (! array_key_exists('cod_amount', $data) || $data['cod_amount'] === null) {
            return $this->refuse(
                'Enter the cash collected: ₱'.number_format($payment->amount_minor / 100, 2).'.',
            );
        }

        $collectedMinor = (int) round(((float) $data['cod_amount']) * 100);
        if ($collectedMinor !== (int) $payment->amount_minor) {
            return $this->refuse(
                'Cash collected must be exactly ₱'.number_format($payment->amount_minor / 100, 2).'.',
            );
        }

        $payment->markPaid();

        return null;
    }

    /**
     * Does this response count as a refusal?
     *
     * Matched case-insensitively and on the stable phrase, because the app
     * offers "Buyer refused" as a fixed option while "Buyer refused -
     * changed their mind" is still a refusal.
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
