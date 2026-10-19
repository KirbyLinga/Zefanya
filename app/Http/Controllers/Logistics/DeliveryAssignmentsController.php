<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\MarkDeliveryFailedRequest;
use App\Models\SellerOrder;
use App\Services\CourierAssignmentService;
use App\Services\SellerOrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Rider delivery screen: parcels assigned to a delivery courier
 * (Sorted → AssignedToRider) awaiting the final delivery leg.
 *
 * Flow:
 *   AssignedToRider  →  OutForDelivery     (delivery rider starts the run)
 *   OutForDelivery   →  Delivered           (delivery rider marks delivered, releases courier)
 *   OutForDelivery   →  DeliveryFailed      (could not deliver)
 */
class DeliveryAssignmentsController extends Controller
{
    private const DELIVERY_STATUSES = [
        OrderStatus::AssignedToRider,
        OrderStatus::OutForDelivery,
        OrderStatus::DeliveryFailed,
    ];

    public function __construct(
        private readonly SellerOrderStatusService $status = new SellerOrderStatusService,
        private readonly CourierAssignmentService $assignment = new CourierAssignmentService,
    ) {}

    /**
     * Delivery assignments for this provider's couriers.
     */
    public function index(Request $request): View
    {
        $provider = $request->user('logistics');

        $shipments = SellerOrder::query()
            ->whereIn('status', self::DELIVERY_STATUSES)
            ->whereHas('deliveryCourier.provider', fn ($q) => $q->whereKey($provider->id))
            ->with(['courier.provider', 'deliveryCourier.provider', 'order.buyer', 'seller', 'orderItems.product'])
            ->orderBy('created_at')
            ->get();

        return view('Logistics.DeliveryAssignments.index', ['shipments' => $shipments]);
    }

    /**
     * Start the delivery run: AssignedToRider → OutForDelivery.
     */
    public function startDelivery(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::AssignedToRider) {
            return back()->with('error', 'This delivery has already started.');
        }

        try {
            $this->status->transition(
                $sellerOrder,
                OrderStatus::OutForDelivery,
                note: 'Out for delivery.',
                changedBy: 'system',
                actor: OrderActor::Rider,
                actorId: $sellerOrder->deliveryCourier?->id,
                source: 'web',
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Delivery started.');
    }

    /**
     * Mark delivered: OutForDelivery → Delivered, release the delivery courier.
     */
    public function markDelivered(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::OutForDelivery) {
            return back()->with('error', 'This parcel is not out for delivery.');
        }

        try {
            $this->status->transition(
                $sellerOrder,
                OrderStatus::Delivered,
                note: 'Parcel delivered to buyer.',
                changedBy: 'system',
                actor: OrderActor::Rider,
                actorId: $sellerOrder->deliveryCourier?->id,
                source: 'web',
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $deliveryCourier = $sellerOrder->deliveryCourier;
        if ($deliveryCourier) {
            $this->assignment->release($deliveryCourier);
        }

        return back()->with('success', 'Parcel marked as delivered.');
    }

    /**
     * Show the mark failed form.
     *
     * Returns View|RedirectResponse: the status guard redirects back when the
     * parcel is not out for delivery (a View-only signature would TypeError).
     */
    public function showMarkFailedForm(Request $request, SellerOrder $sellerOrder): View|RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::OutForDelivery) {
            return back()->with('error', 'This parcel is not out for delivery.');
        }

        return view('Logistics.DeliveryAssignments.mark-failed', [
            'sellerOrder' => $sellerOrder,
            'reasons' => DeliveryFailureReason::cases(),
        ]);
    }

    /**
     * Mark delivery failed: OutForDelivery → DeliveryFailed with reason.
     */
    public function markFailed(MarkDeliveryFailedRequest $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::OutForDelivery) {
            return back()->with('error', 'This parcel is not out for delivery.');
        }

        $reason = $request->input('failure_reason');
        $notes = $request->input('failure_notes');

        $reasonLabel = DeliveryFailureReason::tryFrom($reason)?->label() ?? $reason;
        $note = "Delivery failed: {$reasonLabel}.";
        if ($notes) {
            $note .= " Notes: {$notes}";
        }

        try {
            // Store the failure reason/notes AND the transition atomically:
            // one transaction, so a rejected hop rolls the columns back and
            // an OutForDelivery row can never carry a stale failure reason.
            DB::transaction(function () use ($sellerOrder, $reason, $notes, $note): void {
                $sellerOrder->forceFill([
                    'delivery_failure_reason' => $reason,
                    'delivery_failure_notes' => $notes,
                    // Every failed attempt is counted: the retry limit is what
                    // eventually forces a RETURNED parcel.
                    'delivery_attempts' => ((int) $sellerOrder->delivery_attempts) + 1,
                ])->save();

                $this->status->transition(
                    $sellerOrder,
                    OrderStatus::DeliveryFailed,
                    note: $note,
                    changedBy: 'system',
                    actor: OrderActor::Rider,
                    actorId: $sellerOrder->deliveryCourier?->id,
                    source: 'web',
                );
            });
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('warning', 'Delivery marked as failed.');
    }

    /**
     * Reschedule a failed delivery: clear the released courier and assign a
     * new one, back to AssignedToRider. Allowed at most twice (per the agreed
     * default), after which only returnToSeller() is offered.
     */
    public function reschedule(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::DeliveryFailed) {
            return back()->with('error', 'This parcel is not awaiting a re-delivery decision.');
        }

        if ((int) $sellerOrder->delivery_attempts >= 2) {
            return back()->with('error', 'The retry limit has been reached. This parcel must be returned to the seller.');
        }

        // Free the courier slot so a rider can be matched again, then re-assign.
        $sellerOrder->forceFill(['delivery_courier_id' => null])->save();

        $courier = $this->assignment->assignDelivery($sellerOrder->fresh(), $provider);

        if ($courier === null) {
            return back()->with('error', 'No available courier in your fleet serves this destination. Return the parcel to the seller instead.');
        }

        return back()->with('success', "Re-delivery scheduled with {$courier->name}.");
    }

    /**
     * Return a failed parcel to the seller. Restores stock and notifies the
     * seller (handled inside the status service).
     */
    public function returnToSeller(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::DeliveryFailed) {
            return back()->with('error', 'Only a failed delivery can be returned to the seller.');
        }

        try {
            $this->status->transition(
                $sellerOrder,
                OrderStatus::Returned,
                note: 'Parcel returned to the seller.',
                changedBy: 'system',
                actor: OrderActor::Logistics,
                actorId: $provider->id,
                source: 'web',
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Parcel returned to the seller. Stock restored.');
    }
}
