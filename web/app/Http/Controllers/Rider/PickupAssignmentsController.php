<?php

namespace App\Http\Controllers\Rider;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\PickupFailureRequest;
use App\Models\Logistics\PickupSession;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\PickupPolicy;
use App\Services\Logistics\PickupSessionService;
use App\Services\Rider\PickupFailureService;
use App\Services\Rider\RiderOperationsService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $shipments = $operations->pickupAssignments($rider);

        // One card per seller stop. A parcel with no session (booked before sessions existed) is its own stop.
        $sessions = PickupPolicy::sessionsAvailable()
            ? PickupSession::query()->whereKey($shipments->pluck('pickup_session_id')->filter()->unique()->all())->get()->keyBy('id')
            : collect();
        $summaries = $sessions->map(fn (PickupSession $session): array => app(PickupSessionService::class)->summary($session));

        return view('Rider.Pickups.index', [
            'rider' => $rider,
            'shipments' => $shipments,
            'sessions' => $sessions,
            'summaries' => $summaries,
        ]);
    }

    /** The rider has finished at this seller. Parcels not collected wait for a later visit. */
    public function finishStop(Request $request, PickupSession $pickupSession, PickupSessionService $sessions): RedirectResponse
    {
        $courier = $this->operations->operationalCourier($request->user('rider'));

        try {
            $sessions->finish($pickupSession, $courier);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Stop finished. Any parcel you did not collect stays assigned to you for the next visit.');
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
    public function reportFailure(PickupFailureRequest $request, SellerOrder $sellerOrder, PickupFailureService $failures): RedirectResponse
    {
        $rider = $request->user('rider');

        // Ensure rider owns this assignment before proceeding
        $this->operations->assertPickupAssigned($rider, $sellerOrder);

        try {
            $result = $failures->report(
                $rider,
                $sellerOrder,
                $request->validated('failure_reason'),
                $request->validated('failure_notes'),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('rider.pickups.index')
            ->with('status', $result['cancelled']
                ? 'Order cancelled after second pickup failure. Inventory restored.'
                : 'Pickup failure recorded. Seller and logistics notified. Parcel available for retry.');
    }
}
