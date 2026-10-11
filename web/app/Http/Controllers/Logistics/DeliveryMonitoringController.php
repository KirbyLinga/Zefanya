<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only Delivery Monitoring: every parcel this provider's fleet has
 * claimed after pickup, with its current status, the assigned riders and
 * (for failed parcels) the recorded failure reason.
 *
 * This page performs NO status changes — the canonical hops live on:
 *   IncomingParcels   (ReadyForPickup → PickedUp → AtSortingCenter)
 *   ParcelSorting     (AtSortingCenter → Sorted → AssignedToRider)
 *   DeliveryAssignments (AssignedToRider → OutForDelivery → Delivered/DeliveryFailed)
 */
class DeliveryMonitoringController extends Controller
{
    /**
     * Post-pickup delivery statuses visible to monitoring. Narrowed to the
     * delivery legs + terminal states: pickup/sorting progress belongs to the
     * Incoming Parcels and Parcel Sorting screens, not here.
     */
    private const MONITORED_STATUSES = [
        OrderStatus::AssignedToRider,
        OrderStatus::OutForDelivery,
        OrderStatus::Delivered,
        OrderStatus::DeliveryFailed,
    ];

    public function index(Request $request): View
    {
        $provider = $request->user('logistics');

        $shipments = SellerOrder::query()
            ->whereIn('status', self::MONITORED_STATUSES)
            ->where(function ($q) use ($provider): void {
                $q->whereHas('courier.provider', fn ($sub) => $sub->whereKey($provider->id))
                    ->orWhereHas('deliveryCourier.provider', fn ($sub) => $sub->whereKey($provider->id));
            })
            ->with(['courier.provider', 'deliveryCourier.provider', 'order.buyer', 'seller'])
            ->orderBy('created_at')
            ->get();

        return view('Logistics.DeliveryMonitoring.index', ['shipments' => $shipments]);
    }
}
