<?php

namespace App\Http\Controllers\Rider;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\PickupFailureRequest;
use App\Models\Shared\SellerOrder;
use App\Notifications\Admin\PickupFailureException;
use App\Notifications\Logistics\RiderParcelActivity;
use App\Notifications\Rider\PickupFailure;
use App\Notifications\Seller\PickupFailed;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Rider\RiderOperationsService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PickupAssignmentsController extends Controller
{
    public function __construct(
        protected readonly RiderOperationsService $operations,
        protected readonly SellerOrderStatusService $statuses,
    ) {}

    public function index(Request $request, RiderOperationsService $operations): View
    {
        $rider = $request->user('rider');

        return view('Rider.Pickups.index', [
            'rider' => $rider,
            'shipments' => $operations->pickupAssignments($rider),
        ]);
    }

    public function confirm(
        Request $request,
        SellerOrder $sellerOrder,
        RiderOperationsService $operations,
        ParcelHandoffVerification $handoffVerify,
    ): RedirectResponse {
        $rider = $request->user('rider');

        // Ownership before any idempotent success response.
        $operations->assertPickupAssigned($rider, $sellerOrder);

        try {
            $method = null;
            if ($sellerOrder->fresh()?->status !== OrderStatus::PickedUp) {
                $payload = $handoffVerify->assertValidForRider(
                    $rider,
                    $sellerOrder,
                    ParcelHandoffVerification::ACTION_CONFIRM_PICKUP,
                    $request->input('verification_token'),
                );
                $method = $payload['method'] ?? null;
            }

            $operations->confirmPickup($rider, $sellerOrder, $method);

            if ($method !== null) {
                app(ParcelHandoffVerification::class)->consumeAfterSuccess(fn () => $handoffVerify->consumeForRider(
                    $rider,
                    $sellerOrder,
                    ParcelHandoffVerification::ACTION_CONFIRM_PICKUP,
                    $request->input('verification_token'),
                ));
            }
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pickup confirmed. Parcel collected from seller.');
    }

    /**
     * Handle a rider reporting a failed pickup attempt.
     *
     * Validates that the rider has an active assignment for this order,
     * records the failure attempt, releases the rider reservation,
     * and notifies the seller and logistics.
     *
     * First failure: records attempt #1, releases the reservation,
     *    keeps status READY_FOR_PICKUP, notifies seller + logistics.
     *
     * Second failure: records attempt #2, prevents a third attempt,
     *    and cancels the order via the canonical status service so stock is
     *    restored, a pending payment is voided, the audit history is written,
     *    and the parent order rolls up — all inside one locked transaction.
     *
     * @throws ValidationException
     */
    public function reportFailure(PickupFailureRequest $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $rider = $request->user('rider');

        // Ensure rider owns this assignment before proceeding
        $this->operations->assertPickupAssigned($rider, $sellerOrder);

        try {
            return DB::transaction(function () use ($rider, $sellerOrder, $request) {
                $sellerOrder = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
                ParcelIncidentService::assertOperationsAllowed($sellerOrder);
                $reason = $request->validated('failure_reason');
                $attemptNumber = $sellerOrder->pickupAttempts()->max('attempt_number') ?? 0;
                $nextAttempt = $attemptNumber + 1;

                // Two total attempts only: a third report must not resurrect a
                // cancelled parcel or restore stock a second time.
                if ($attemptNumber >= 2) {
                    return back()->with('error', 'Pickup attempts are limited to two; this parcel is already cancelled.');
                }

                // Record the failure attempt
                $sellerOrder->pickupAttempts()->create([
                    'attempt_number' => $nextAttempt,
                    'rider_id' => $rider->id,
                    'failure_reason' => $reason,
                    'failed_at' => now(),
                ]);

                if ($nextAttempt >= 2) {
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
                        source: 'web',
                        expectedFrom: OrderStatus::ReadyForPickup,
                    );

                    // Notify all stakeholders
                    $rider->notify(new PickupFailure($sellerOrder, $nextAttempt, $reason));

                    $sellerOrder->seller->notify(
                        new PickupFailed($sellerOrder, $nextAttempt, $reason)
                    );

                    // Notify selected logistics providers
                    $selectedProvider = $sellerOrder->selectedLogisticsProvider();
                    if ($selectedProvider) {
                        $selectedProvider->notify(new RiderParcelActivity(
                            $sellerOrder,
                            'pickup_failure',
                            'Attempt #'.$nextAttempt.': '.$reason,
                        ));
                    }

                    // Admin receives an exception alert.
                    $adminEmail = config('admin.notification_email') ?: config('mail.from.address');
                    if (filled($adminEmail)) {
                        Notification::route('mail', $adminEmail)->notify(
                            new PickupFailureException(
                                $sellerOrder,
                                $nextAttempt,
                                $reason,
                                $request->validated('failure_notes'),
                            )
                        );
                    }

                    return redirect()
                        ->route('rider.pickups.index')
                        ->with('status', 'Order cancelled after second pickup failure. Inventory restored.');
                }

                // --- FIRST FAILURE ---
                // Release the rider's reservation for this parcel. The courier
                // stays assigned so the same rider can make the single retry.
                $this->operations->releasePickupReservation($sellerOrder, $rider);

                // Notify seller
                $sellerOrder->seller->notify(
                    new PickupFailed($sellerOrder, $nextAttempt, $reason)
                );

                // Notify selected logistics provider
                $selectedProvider = $sellerOrder->selectedLogisticsProvider();
                if ($selectedProvider) {
                    $selectedProvider->notify(new RiderParcelActivity(
                        $sellerOrder,
                        'pickup_failure',
                        'Attempt #'.$nextAttempt.': '.$reason,
                    ));
                }

                return redirect()
                    ->route('rider.pickups.index')
                    ->with('status', 'Pickup failure recorded. Seller and logistics notified. Parcel available for retry.');
            });
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
