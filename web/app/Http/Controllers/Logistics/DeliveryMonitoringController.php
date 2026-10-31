<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LogisticsStatusOverrideService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Delivery Monitoring: every parcel this provider's fleet has
 * claimed after pickup, with its current status, the assigned riders and
 * (for failed parcels) the recorded failure reason.
 *
 * Normal operational status changes — the canonical hops live on:
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
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) $request->query('search', ''));
        $provider = $request->user('logistics');

        $shipments = SellerOrder::query()
            ->whereIn('status', self::MONITORED_STATUSES)
            ->visibleToProvider((int) $provider->id)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($matches) use ($search): void {
                    $matches->whereLike('tracking_number', '%'.$search.'%');
                    $orderId = ltrim($search, '#');
                    if (ctype_digit($orderId)) {
                        $matches->orWhere('seller_orders.id', $orderId)->orWhere('order_id', $orderId);
                    }
                    $matches->orWhereHas('order.buyer', function ($buyers) use ($search): void {
                        foreach (preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                            $buyers->where(function ($name) use ($part): void {
                                $name->whereLike('first_name', '%'.$part.'%')
                                    ->orWhereLike('last_name', '%'.$part.'%')
                                    ->orWhereLike('middle_initial', rtrim($part, '.'));
                            });
                        }
                    });
                });
            })->with(['courier.provider', 'deliveryCourier.provider', 'order.buyer', 'seller'])
            ->orderBy('created_at')->orderBy('id')
            ->paginate(25)->withQueryString();

        return view('Logistics.DeliveryMonitoring.index', ['shipments' => $shipments, 'search' => $search]);
    }

    public function overrideStatus(Request $request, SellerOrder $sellerOrder, LogisticsStatusOverrideService $service): RedirectResponse
    {
        $provider = $request->user('logistics');
        $this->authorizeForUser($provider, 'advance-shipment', $sellerOrder);
        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'expected_status' => ['required', Rule::enum(OrderStatus::class)],
            'reason' => ['required', 'string', 'min:10', 'max:300'],
        ]);
        try {
            $service->override($provider, $sellerOrder,
                OrderStatus::from($data['expected_status']), OrderStatus::from($data['status']), $data['reason']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        return redirect()->route('logistics.delivery-monitoring.show', $sellerOrder)
            ->with('success', 'Status updated. The reason and status change were recorded in the audit log.');
    }
}
