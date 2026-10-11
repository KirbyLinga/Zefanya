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
}
