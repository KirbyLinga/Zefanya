<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Services\CourierAssignmentService;
use App\Services\SellerOrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sorting center screen: parcels that have arrived at the sorting center
 * (ReadyForPickup → PickedUp → AtSortingCenter).
 *
 * Flow:
 *   AtSortingCenter  →  Sorted              (logistics sorts the parcel)
 *   Sorted           →  AssignedToRider      (logistics assigns a delivery courier)
 */
class ParcelSortingController extends Controller
{
    public function __construct(
        private readonly SellerOrderStatusService $status = new SellerOrderStatusService,
        private readonly CourierAssignmentService $assignment = new CourierAssignmentService,
    ) {}

    /**
     * Parcels waiting at the sorting center to be sorted and routed.
     */
    public function index(Request $request): View
    {
        $provider = $request->user('logistics');

        $tab = $request->query('tab');
        if ($tab !== 'assigned') {
            $tab = 'sorting';
        }

        $baseQuery = SellerOrder::query()
            ->where(function ($q) use ($provider): void {
                $q->whereHas('courier.provider', fn ($sub) => $sub->whereKey($provider->id))
                    ->orWhereHas('deliveryCourier.provider', fn ($sub) => $sub->whereKey($provider->id));
            });

        // Tab counts for the UI
        $sortingCount = (clone $baseQuery)
            ->whereIn('status', [OrderStatus::AtSortingCenter, OrderStatus::Sorted])
            ->count();

        $assignedCount = (clone $baseQuery)
            ->where('status', OrderStatus::AssignedToRider)
            ->count();

        $statuses = $tab === 'assigned'
            ? [OrderStatus::AssignedToRider]
            : [OrderStatus::AtSortingCenter, OrderStatus::Sorted];

        $shipments = (clone $baseQuery)
            ->whereIn('status', $statuses)
            ->with([
                'courier.provider',
                'deliveryCourier.provider',
                'order.buyer',
                'seller',
                'orderItems.product',
            ])
            ->orderBy('created_at')
            ->get();

        return view('Logistics.ParcelSorting.index', [
            'shipments' => $shipments,
            'tab' => $tab,
            'sortingCount' => $sortingCount,
            'assignedCount' => $assignedCount,
        ]);
    }

    /**
     * Sort a parcel: AtSortingCenter → Sorted.
     */
    public function sort(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::AtSortingCenter) {
            return back()->with('error', 'This parcel has already been sorted.');
        }

        try {
            $this->status->transition(
                $sellerOrder,
                OrderStatus::Sorted,
                note: 'Parcel sorted and routed for delivery.',
                changedBy: 'system',
                actor: OrderActor::Logistics,
                actorId: $provider->id,
                source: 'web',
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Parcel sorted.');
    }

    /**
     * Assign a delivery courier: Sorted → AssignedToRider (sets delivery_courier_id).
     */
    public function assignDelivery(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::Sorted) {
            return back()->with('error', 'This parcel must be sorted first.');
        }

        if ($sellerOrder->deliveryCourier !== null) {
            return back()->with('warning', 'A delivery courier is already assigned.');
        }

        $courier = $this->assignment->assignDelivery($sellerOrder, $provider);

        if ($courier === null) {
            return back()->with('error', 'No available courier in your fleet serves this destination.');
        }

        return back()->with('success', "Delivery courier {$courier->name} assigned.");
    }
}
