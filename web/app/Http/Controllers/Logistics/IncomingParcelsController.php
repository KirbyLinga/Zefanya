<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\SellerOrder;
use App\Services\CourierAssignmentService;
use App\Services\SellerOrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rider pickup screen: parcels at ReadyForPickup (awaiting pickup)
 * and PickedUp (in transit to the sorting center).
 *
 * Flow:
 *   ReadyForPickup  →  PickedUp            (rider assigns self as pickup courier)
 *   PickedUp        →  AtSortingCenter      (rider hands parcel to sorting center, releases courier)
 */
class IncomingParcelsController extends Controller
{
    private const PICKUP_STATUSES = [
        OrderStatus::ReadyForPickup,
        OrderStatus::PickedUp,
    ];

    public function __construct(
        private readonly SellerOrderStatusService $status = new SellerOrderStatusService,
        private readonly CourierAssignmentService $assignment = new CourierAssignmentService,
    ) {}

    /**
     * Shipments waiting for pickup or in transit to the sorting center.
     */
    public function index(Request $request): View
    {
        $provider = $request->user('logistics');

        $shipments = SellerOrder::query()
            ->whereIn('status', self::PICKUP_STATUSES)
            ->where(function ($q) use ($provider) {
                // Orders with a pickup courier from this provider, or not yet
                // assigned any courier (still at ReadyForPickup).
                $q->whereHas('courier.provider', fn ($sub) => $sub->whereKey($provider->id))
                    ->orWhereNull('courier_id');
            })
            ->with(['courier.provider', 'deliveryCourier', 'order.buyer', 'seller', 'orderItems.product', 'orderItems.variant'])
            ->orderBy('created_at')
            ->get();

        return view('Logistics.IncomingParcels.index', [
            'shipments' => $shipments,
            'provider' => $provider,
        ]);
    }

    /**
     * Accept a pickup assignment: assign the pickup courier and advance
     * the order to PickedUp. Generates a tracking number on pickup.
     */
    public function pickup(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::ReadyForPickup) {
            return back()->with('error', 'This parcel is no longer available for pickup.');
        }

        $courier = $this->assignment->assignPickup($sellerOrder, $provider);

        $sellerOrder = $sellerOrder->fresh();

        if ($courier === null) {
            return back()->with('error', 'No available courier in your fleet serves this area.');
        }

        if ($sellerOrder->status === OrderStatus::PickedUp) {
            return back()->with('success', "Parcel #{$sellerOrder->id} picked up and assigned to {$courier->name}.");
        }

        return back()->with('error', 'Unable to assign a pickup courier right now.');
    }

    /**
     * Hand the parcel to the sorting center: advance to AtSortingCenter
     * and release the pickup courier back to available.
     */
    public function toSortingCenter(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::PickedUp) {
            return back()->with('error', 'This parcel is not in the pickup phase.');
        }

        try {
            $this->status->transition(
                $sellerOrder,
                OrderStatus::AtSortingCenter,
                note: 'Parcel handed to sorting center.',
                changedBy: 'system',
                actor: OrderActor::Rider,
                actorId: $sellerOrder->courier?->id,
                source: 'web',
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pickupCourier = $sellerOrder->courier;
        if ($pickupCourier) {
            $this->assignment->release($pickupCourier);
        }

        return back()->with('success', 'Parcel delivered to sorting center. Pickup courier released.');
    }
}
