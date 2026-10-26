<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\OrderStatus;
use App\Enums\ParcelIncidentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ParcelIncident\StoreIncidentRequest;
use App\Models\Courier;
use App\Models\SellerOrder;
use App\Services\CourierAssignmentService;
use App\Services\LogisticsHubService;
use App\Services\ParcelIncidentService;
use App\Services\ParcelReturnService;
use App\Services\RiderNotifier;
use App\Services\SellerOrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Rider delivery screen: parcels assigned to a delivery courier
 * (Sorted → AssignedToRider) awaiting the final delivery leg.
 *
 * Flow:
 *   AssignedToRider  →  OutForDelivery     (delivery rider starts the run)
 *   OutForDelivery   →  Delivered           (delivery rider marks delivered, releases courier)
 *   OutForDelivery   →  DeliveryFailed      (could not deliver)
 *
 * Logistics only manages assignment, rescheduling, and returns.
 * The Rider performs start/deliver/failed via their own panel.
 */
class DeliveryAssignmentsController extends Controller
{
    private const DELIVERY_STATUSES = [
        OrderStatus::Sorted,
        OrderStatus::AssignedToRider,
        OrderStatus::OutForDelivery,
        OrderStatus::DeliveryFailed,
        OrderStatus::Delivered,
        OrderStatus::Completed,
    ];

    public function __construct(
        private readonly SellerOrderStatusService $status = new SellerOrderStatusService,
        private readonly CourierAssignmentService $assignment = new CourierAssignmentService,
        private readonly RiderNotifier $riderNotifier = new RiderNotifier,
        private readonly ParcelIncidentService $incident = new ParcelIncidentService,
    ) {}

    /**
     * Delivery assignments for this provider's couriers.
     */
    public function index(Request $request): View
    {
        $provider = $request->user('logistics');

        $shipments = SellerOrder::query()
            ->whereIn('status', self::DELIVERY_STATUSES)
            ->heldBy((int) $provider->id)
            ->with([
                'deliveryCourier' => fn ($q) => $q->withWorkload(),
                'deliveryAssignments.courier',
                'originStation',
                'deliveryCourier.rider.station',
                'courier.provider',
                'deliveryCourier.provider',
                'deliveryCourier.rider.station',
                'destinationArea.station',
                'deliveryProof',
                'payment',
                'parcelReturn',
                'order.buyer',
                'seller',
                'orderItems.product',
                'orderItems.variant',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $availableCouriers = Courier::query()
            ->eligibleForAssignment()->withWorkload()->withActiveWeight()
            ->where('logistics_provider_id', $provider->id)
            ->with(['rider.station'])
            ->orderBy('name')
            ->get();

        return view('Logistics.DeliveryAssignments.index', [
            'shipments' => $shipments,
            'availableCouriers' => $availableCouriers,
        ]);
    }

    public function show(Request $request, SellerOrder $sellerOrder): View
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        $parcel = $sellerOrder->loadMissing([
            'deliveryCourier.provider', 'deliveryAssignments.courier', 'originStation',
            'destinationArea.station', 'deliveryProof', 'parcelReturn',
            'order.buyer', 'seller', 'payment', 'orderItems.product', 'orderItems.variant',
        ]);

        $openIncident = $this->incident->hasOpenIncident($parcel);

        return view('Logistics.DeliveryMonitoring.show', [
            'sellerOrder' => $parcel,
            'openIncident' => $openIncident,
            'incident' => $openIncident ? $parcel->incidentSet()->where('status', ParcelIncidentStatus::Open->value)->latest('id')->first() : null,
        ]);
    }

    public function reportIncident(StoreIncidentRequest $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $this->incident->report($sellerOrder, $request->user('logistics'), $request->validated());

        return redirect()->route('logistics.delivery-monitoring.show', $sellerOrder)
            ->with('success', 'Incident reported. Parcel operations are blocked pending Admin resolution.');
    }

    public function reassign(Request $request, SellerOrder $sellerOrder, LogisticsHubService $hubs): RedirectResponse
    {
        $provider = $request->user('logistics');
        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);
        if (($reason = $hubs->assignmentBlockReason($provider->id)) !== null) {
            return back()->with('error', $reason);
        }
        if (! in_array($sellerOrder->status, [OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::OutForDelivery], true)) {
            return back()->with('error', 'This delivery leg cannot be reassigned.');
        }
        $data = $request->validate([
            'reason' => [$request->filled('courier_id') ? 'required' : 'nullable', 'string', 'max:500'],
            'courier_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
            'expected_courier_id' => ['required', 'integer'],
        ]);
        $courier = $this->assignment->reassign(
            $sellerOrder, $provider, 'delivery',
            filled($data['courier_id'] ?? null) ? (int) $data['courier_id'] : null,
            $data['reason'], (int) $data['expected_courier_id'],
        );

        return $courier === null
            ? back()->with('error', 'No eligible replacement Rider is available for this delivery leg.')
            : back()->with('success', "Delivery reassigned to {$courier->name}.");
    }

    /**
     * Schedule the single retry after the first failure. The second failure
     * permits return only; replacement and transition commit atomically.
     */
    public function reschedule(Request $request, SellerOrder $sellerOrder, LogisticsHubService $hubs): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::DeliveryFailed) {
            return back()->with('error', 'This parcel is not awaiting a re-delivery decision.');
        }

        if ($sellerOrder->hasActiveReturn()) {
            return back()->with('error', 'Physical return is already active. Open the return queue to continue reverse transport.');
        }

        if (! SellerOrderStatusService::canRetryDelivery($sellerOrder)) {
            return back()->with('error', (int) $sellerOrder->delivery_attempts >= SellerOrderStatusService::MAX_DELIVERY_ATTEMPTS
                ? 'The two-attempt limit has been reached. Return this parcel to the seller.'
                : 'The failed-attempt history is incomplete. Review it before scheduling a retry.');
        }

        if (($reason = $hubs->assignmentBlockReason($provider->id)) !== null) {
            return back()->with('error', $reason);
        }

        $data = $request->validate([
            'courier_id' => [
                'nullable',
                'integer',
                Rule::exists('couriers', 'id')->where('logistics_provider_id', $provider->id),
            ],
        ]);

        $courier = $this->assignment->assignDelivery(
            $sellerOrder,
            $provider,
            preferredCourierId: filled($data['courier_id'] ?? null) ? (int) $data['courier_id'] : null,
            rescheduled: true,
            reason: $data['reason'] ?? null,
        );

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

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $record = app(ParcelReturnService::class)->request($sellerOrder, $provider,
            $data['reason'] ?? 'Logistics requested physical return after failed delivery.');

        return redirect()->route('logistics.returns.show', $record)->with('success', 'Physical return requested. Seller receipt is required before completion or stock restoration.');
    }
}
