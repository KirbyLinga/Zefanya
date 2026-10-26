<?php

namespace App\Http\Controllers\Rider;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\PickupFailureRequest;
use App\Models\Rider;
use App\Models\SellerOrder;
use App\Notifications\Admin\PickupFailureException;
use App\Notifications\Logistics\RiderParcelActivity;
use App\Notifications\Rider\PickupFailure;
use App\Notifications\Seller\PickupFailed;
use App\Services\ParcelHandoffVerification;
use App\Services\ParcelIncidentService;
use App\Services\RiderHandoffVerification;
use App\Services\RiderOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PickupAssignmentsController extends Controller
{
    public function __construct(protected readonly RiderOperationsService $operations) {}

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
        RiderHandoffVerification $handoffVerify,
    ): RedirectResponse {
        $rider = $request->user('rider');

        // Ownership before any idempotent success response.
        $operations->assertPickupAssigned($rider, $sellerOrder);

        try {
            $method = null;
            if ($sellerOrder->fresh()?->status !== OrderStatus::PickedUp) {
                $payload = $handoffVerify->assertValid(
                    $rider,
                    $sellerOrder,
                    RiderHandoffVerification::ACTION_CONFIRM_PICKUP,
                    $request->input('verification_token'),
                );
                $method = $payload['method'] ?? null;
            }

            $operations->confirmPickup($rider, $sellerOrder, $method);

            if ($method !== null) {
                app(ParcelHandoffVerification::class)->consumeAfterSuccess(fn () => $handoffVerify->consume(
                    $rider,
                    $sellerOrder,
                    RiderHandoffVerification::ACTION_CONFIRM_PICKUP,
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
     * First failure: records attempt #1, releases reservation,
     *    keeps status READY_FOR_PICKUP, notifies seller + logistics.
     *
     * Second failure: records attempt #2, prevents third attempt,
     *    cancels order via existing canonical mechanism, notifies buyer/seller/admin.
     *
     * @throws ValidationException
     */
    public function reportFailure(PickupFailureRequest $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $rider = auth('rider')->rider();

        // Ensure rider owns this assignment before proceeding
        $this->operations->assertPickupAssigned($rider, $sellerOrder);

        return DB::transaction(function () use ($rider, $sellerOrder, $request) {
            $sellerOrder = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($sellerOrder);
            $attemptNumber = $sellerOrder->pickupAttempts()->max('attempt_number') ?? 0;
            $nextAttempt = $attemptNumber + 1;

            // Record the failure attempt
            $sellerOrder->pickupAttempts()->create([
                'attempt_number' => $nextAttempt,
                'rider_id' => $rider->id,
                'failure_reason' => $request->validated('failure_reason'),
                'failed_at' => now(),
            ]);

            if ($nextAttempt >= 2) {
                // --- SECOND FAILURE ---
                // Cancel the order using the existing canonical status service
                // This will restore inventory exactly once
                $sellerOrder->status = OrderStatus::Cancelled;
                $sellerOrder->save();

                // Notify all stakeholders
                Notification::to($rider)->route('rider.notifications.index')->notify(new PickupFailure(
                    $sellerOrder,
                    'second',
                    $request->validated('failure_reason')
                ));

                Notification::to($sellerOrder->seller)
                    ->route('seller.orders.show', $sellerOrder)
                    ->notify(new PickupFailed($sellerOrder, $nextAttempt, $request->validated('failure_reason')));

                // Notify selected logistics providers
                $selectedProvider = $sellerOrder->selectedLogisticsProvider();
                if ($selectedProvider) {
                    $selectedProvider->notify(new RiderParcelActivity($sellerOrder, 'pickup_failure', [
                        'attempt' => $nextAttempt,
                        'reason' => $request->validated('failure_reason'),
                    ]));
                }

                // Notify Admin via RiderParcelActivity
                // Admin receives exception notification
                $adminException = $sellerOrder->exceptions()->create([
                    'type' => 'pickup_failure',
                    'rider_id' => $rider->id,
                    'details' => 'Second pickup failure: '.$request->validated('failure_reason'),
                    'created_by' => 'rider',
                ]);

                // Notify admin through the existing exception notification channel
                Notification::route('mail', config('admin.email'))
                    ->notify(new PickupFailureException($adminException));

                return redirect()
                    ->route('rider.pickups.index')
                    ->with('status', 'Order cancelled after second pickup failure. Inventory restored.');
            }

            // --- FIRST FAILURE ---
            // Release the rider's reservation for this parcel
            $this->operations->releaseRiderReservation($sellerOrder, $rider);

            // Keep canonical status at READY_FOR_PICKUP (no status change needed)

            // Notify seller
            Notification::to($sellerOrder->seller)
                ->route('seller.orders.show', $sellerOrder)
                ->notify(new PickupFailed($sellerOrder, $nextAttempt, $request->validated('failure_reason')));

            // Notify selected logistics provider
            $selectedProvider = $sellerOrder->selectedLogisticsProvider();
            if ($selectedProvider) {
                $selectedProvider->notify(new RiderParcelActivity($sellerOrder, 'pickup_failure', [
                    'attempt' => $nextAttempt,
                    'reason' => $request->validated('failure_reason'),
                ]));
            }

            return redirect()
                ->route('rider.pickups.index')
                ->with('status', 'Pickup failure recorded. Seller and logistics notified. Parcel available for retry.');
        });
    }
}
