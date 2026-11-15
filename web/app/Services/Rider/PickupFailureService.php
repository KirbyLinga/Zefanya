<?php

namespace App\Services\Rider;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Exceptions\StaleStateException;
use App\Models\Rider\Rider;
use App\Models\Shared\SellerOrder;
use App\Notifications\Admin\PickupFailureException;
use App\Notifications\Logistics\RiderParcelActivity;
use App\Notifications\Rider\PickupFailure;
use App\Notifications\Seller\PickupFailed;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * A rider's failed pickup attempt: the rule the Rider web panel and the
 * Rider mobile API share.
 *
 * First failure: records attempt #1, releases the rider reservation, keeps
 * the parcel READY_FOR_PICKUP and notifies the seller and logistics.
 *
 * Second failure: records attempt #2 and cancels through the canonical status
 * service (stock restored, pending payment voided, history written, parent
 * order rolled up) inside one locked transaction, then notifies the rider,
 * seller, logistics and the Admin exception inbox.
 *
 * Attempts are limited to two; a third report changes nothing.
 */
class PickupFailureService
{
    public const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly RiderOperationsService $operations,
        private readonly SellerOrderStatusService $statuses,
    ) {}

    /**
     * The caller must already have asserted the pickup assignment
     * (RiderOperationsService::assertPickupAssigned).
     *
     * @param  int|null  $expectedAttempt  The attempt number the client believes it is
     *                                     recording. When given, a mismatch under the row
     *                                     lock is a stale/duplicate request (409), so a
     *                                     retried first report can never become the second.
     * @param  OrderStatus|null  $expectedStatus  When given, the parcel must still be in this
     *                                            status under the row lock (409 otherwise).
     * @return array{attempt: int, cancelled: bool, parcel: SellerOrder}
     *
     * @throws StaleStateException when attempts are exhausted or the request is stale
     */
    public function report(
        Rider $rider,
        SellerOrder $sellerOrder,
        string $reason,
        ?string $notes = null,
        string $source = 'web',
        ?int $expectedAttempt = null,
        ?OrderStatus $expectedStatus = null,
    ): array {
        return DB::transaction(function () use ($rider, $sellerOrder, $reason, $notes, $source, $expectedAttempt, $expectedStatus): array {
            $sellerOrder = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($sellerOrder);
            $attemptNumber = (int) ($sellerOrder->pickupAttempts()->max('attempt_number') ?? 0);
            $nextAttempt = $attemptNumber + 1;

            // Two total attempts only: a third report must not resurrect a
            // cancelled parcel or restore stock a second time.
            if ($attemptNumber >= self::MAX_ATTEMPTS) {
                throw new StaleStateException('Pickup attempts are limited to two; this parcel is already cancelled.');
            }

            if ($expectedStatus !== null && $sellerOrder->status !== $expectedStatus) {
                throw new StaleStateException('This pickup is no longer waiting to be collected.');
            }

            if ($expectedAttempt !== null && $expectedAttempt !== $nextAttempt) {
                throw new StaleStateException("Pickup attempt #{$attemptNumber} is already recorded. Refresh your pickups.");
            }

            $sellerOrder->pickupAttempts()->create([
                'attempt_number' => $nextAttempt,
                'rider_id' => $rider->id,
                'failure_reason' => $reason,
                'failed_at' => now(),
            ]);

            if ($nextAttempt >= self::MAX_ATTEMPTS) {
                // --- SECOND FAILURE ---
                // Cancel through the canonical status service. The service
                // restores inventory, voids the pending payment, records
                // the status history, and rolls up the parent order.
                $this->statuses->transition(
                    $sellerOrder,
                    OrderStatus::Cancelled,
                    note: 'Second pickup failure: '.$reason,
                    changedBy: $rider->fullName(),
                    actor: OrderActor::Rider,
                    actorId: $this->operations->operationalCourier($rider)->id,
                    source: $source,
                    expectedFrom: OrderStatus::ReadyForPickup,
                );

                $rider->notify(new PickupFailure($sellerOrder, $nextAttempt, $reason));
                $sellerOrder->seller->notify(new PickupFailed($sellerOrder, $nextAttempt, $reason));
                $this->notifyLogistics($sellerOrder, $nextAttempt, $reason);

                // Admin receives an exception alert.
                $adminEmail = config('admin.notification_email') ?: config('mail.from.address');
                if (filled($adminEmail)) {
                    Notification::route('mail', $adminEmail)->notify(
                        new PickupFailureException($sellerOrder, $nextAttempt, $reason, $notes)
                    );
                }

                return ['attempt' => $nextAttempt, 'cancelled' => true, 'parcel' => $sellerOrder];
            }

            // --- FIRST FAILURE ---
            // Release the rider's reservation for this parcel. The courier
            // stays assigned so the same rider can make the single retry.
            $this->operations->releasePickupReservation($sellerOrder, $rider);

            $sellerOrder->seller->notify(new PickupFailed($sellerOrder, $nextAttempt, $reason));
            $this->notifyLogistics($sellerOrder, $nextAttempt, $reason);

            return ['attempt' => $nextAttempt, 'cancelled' => false, 'parcel' => $sellerOrder];
        });
    }

    private function notifyLogistics(SellerOrder $sellerOrder, int $attempt, string $reason): void
    {
        $selectedProvider = $sellerOrder->selectedLogisticsProvider();
        if ($selectedProvider) {
            $selectedProvider->notify(new RiderParcelActivity(
                $sellerOrder,
                'pickup_failure',
                'Attempt #'.$attempt.': '.$reason,
            ));
        }
    }
}
