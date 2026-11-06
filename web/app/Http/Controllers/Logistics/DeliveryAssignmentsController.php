<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ParcelIncident\StoreIncidentRequest;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Rider\Courier;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\LogisticsStatusOverrideService;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Logistics\RouteExecutionService;
use App\Services\Rider\RiderNotifier;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public const OUT_FOR_DELIVERY_REASSIGN_MESSAGE = 'This parcel is out for delivery and physically with the Rider, so it cannot be moved to another Rider directly. '
        .'The Rider records the delivery attempt to bring it back under Station custody, then schedule the final attempt with a new Rider. '
        .'If the Rider cannot return the parcel, report an incident.';

    public const STILL_WITH_RIDER_MESSAGE = 'This parcel is still with the Rider who made the failed attempt. '
        .'Scan it on the Scan page to receive it back at the Station Hub before giving the final attempt to another Rider. '
        .'The same Rider can take the final attempt without a scan.';

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

        $openIncident = ParcelIncidentService::movementBlockers($parcel)->exists();

        return view('Logistics.DeliveryMonitoring.show', [
            'sellerOrder' => $parcel,
            'routeDetails' => app(RouteExecutionService::class)->trackingSummary($parcel),
            'overrideOptions' => app(LogisticsStatusOverrideService::class)->options($parcel),
            'openIncident' => $openIncident,
            'incident' => $openIncident ? ParcelIncidentService::movementBlockers($parcel)->latest('id')->first() : null,
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
        if ($sellerOrder->status === OrderStatus::OutForDelivery) {
            return back()->with('error', self::OUT_FOR_DELIVERY_REASSIGN_MESSAGE);
        }
        if (! in_array($sellerOrder->status, [OrderStatus::Sorted, OrderStatus::AssignedToRider], true)) {
            return back()->with('error', 'This delivery leg cannot be reassigned.');
        }
        $data = $request->validate([
            'courier_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
            'expected_courier_id' => ['required', 'integer'],
        ]);
        if (($limit = $this->assignment->manualRiderBlockReason(filled($data['courier_id'] ?? null) ? (int) $data['courier_id'] : null, $provider->id)) !== null) {
            return back()->withInput()->with('error', $limit);
        }
        $courier = $this->assignment->reassign(
            $sellerOrder, $provider, 'delivery',
            filled($data['courier_id'] ?? null) ? (int) $data['courier_id'] : null,
            $data['reason'], (int) $data['expected_courier_id'],
        );

        if ($courier === null) {
            // The service returns null for a lost race too; say which one happened.
            return back()->with('error', (int) $sellerOrder->fresh()->delivery_courier_id !== (int) $data['expected_courier_id']
                ? 'This delivery was already reassigned by someone else. The page now shows the current Rider.'
                : 'No eligible replacement Rider is available for this delivery leg.');
        }

        return back()->with('success', "Delivery reassigned to {$courier->name}.");
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
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $preferred = filled($data['courier_id'] ?? null) ? (int) $data['courier_id'] : null;
        $withRider = ! $sellerOrder->isBackAtStationAfterFailure();
        if ($withRider && $preferred !== null && $preferred !== (int) $sellerOrder->delivery_courier_id) {
            return back()->with('error', self::STILL_WITH_RIDER_MESSAGE);
        }

        $courier = $this->assignment->assignDelivery(
            $sellerOrder,
            $provider,
            preferredCourierId: $preferred,
            rescheduled: true,
            reason: $data['reason'] ?? null,
        );

        if ($courier === null) {
            return back()->with('error', $withRider
                ? 'The Rider who made the failed attempt cannot take the final attempt and the parcel is still with them. '.self::STILL_WITH_RIDER_MESSAGE
                : 'No available courier in your fleet serves this destination. Return the parcel to the seller instead.');
        }

        return back()->with('success', "Re-delivery scheduled with {$courier->name}.");
    }

    /**
     * Station Hub receives a parcel back after a failed delivery attempt. The
     * tracking number must be verified on the Scan page first (camera or
     * manual), the same proof used for pickup station receipt. No status
     * changes: it records custody so the final attempt may go to any Rider.
     */
    public function receiveBack(Request $request, SellerOrder $sellerOrder, ParcelHandoffVerification $verify): RedirectResponse
    {
        $provider = $request->user('logistics');
        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if ($sellerOrder->status !== OrderStatus::DeliveryFailed || $sellerOrder->hasActiveReturn()) {
            return back()->with('error', 'Only a parcel with a failed delivery attempt can be received back here.');
        }
        if ($sellerOrder->isBackAtStationAfterFailure()) {
            return back()->with('success', 'This parcel is already back at the Station Hub. No change was made.');
        }
        $hubId = $this->assignment->failedReturnStationId($sellerOrder);
        $token = $request->input('verification_token');

        try {
            $verify->assertValidForLogistics($provider, $sellerOrder, ParcelHandoffVerification::ACTION_FAILED_RETURN_RECEIPT, $token, $hubId);
            DB::transaction(function () use ($sellerOrder, $provider, $hubId): void {
                $parcel = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
                ParcelIncidentService::assertOperationsAllowed($parcel);
                if ($parcel->status !== OrderStatus::DeliveryFailed) {
                    throw ValidationException::withMessages(['parcel' => 'The parcel status changed. Refresh the page.']);
                }
                $parcel->forceFill(['returned_to_station_at' => now(), 'custody_provider_id' => $provider->id, 'custody_hub_id' => $hubId])->save();
                OperationsAuditLog::record($provider, 'failed_parcel_received_back', $parcel, 'Parcel #'.$parcel->id,
                    ['custody' => 'Rider after failed attempt'], ['custody' => 'Station Hub #'.$hubId], null);
            });
            $verify->consumeAfterSuccess(fn () => $verify->consumeForLogistics($provider, $sellerOrder, ParcelHandoffVerification::ACTION_FAILED_RETURN_RECEIPT, $token, $hubId));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('logistics.delivery-assignments.index')
            ->with('success', 'Parcel received back at the Station Hub. The final attempt can now go to any eligible Rider.');
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
