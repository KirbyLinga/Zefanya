<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\Area;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Rider pickup screen: parcels at ReadyForPickup (awaiting pickup)
 * and PickedUp (in transit to the sorting center).
 *
 * Flow:
 *   ReadyForPickup  →  automatically reserved and notified (Logistics can
 *                      override/retry), then the rider confirms collection
 *                      with F2 verification → PickedUp
 *   PickedUp        →  AtSortingCenter      (Logistics confirms station
 *                      receipt, releases the pickup courier)
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

        $highlightedId = $request->integer('highlight') ?: $request->integer('parcel');

        $shipments = SellerOrder::query()
            ->whereIn('status', self::PICKUP_STATUSES)
            ->where(function ($q) use ($provider): void {
                // Selected carriers and current custodians must retain visibility
                // even if origin coverage changes before a rider is reserved.
                $q->heldBy((int) $provider->id)
                    ->orWhere(function ($unclaimed) use ($provider) {
                        // Only unowned legacy parcels are offered by coverage.
                        $unclaimed->whereNull('custody_provider_id')
                            ->whereNull('carrier_provider_id')
                            ->whereNull('courier_id')
                            ->whereIn('seller_id', Seller::query()
                                ->select('id')
                                ->whereIn('barangay_code', Area::query()
                                    ->where('logistics_provider_id', $provider->id)
                                    ->operationalCoverage()
                                    ->select('barangay_code')));
                    });
            })
            ->when($highlightedId, fn ($q) => $q->whereKey($highlightedId))
            ->with([
                'courier' => fn ($q) => $q->withWorkload(),
                'deliveryAssignments.courier',
                'originStation',
                'deliveryCourier.rider.station',
                'courier.provider',
                'courier.rider.station',
                'deliveryCourier',
                'order.buyer',
                'seller',
                'orderItems.product',
                'orderItems.variant',
            ])
            ->orderBy('created_at')->orderBy('id')
            ->paginate(25)->withQueryString();

        // The Station Hub that will collect each parcel: the frozen origin once a
        // reservation exists, otherwise the coverage station for the seller's
        // barangay. The initial Assign Rider dropdown only offers Riders from it.
        $barangays = $shipments->getCollection()
            ->map(fn (SellerOrder $shipment): ?string => $shipment->sellerBarangayCode())
            ->filter()->unique()->values();

        $stationByBarangay = $barangays->isEmpty() ? collect() : Area::query()
            ->where('logistics_provider_id', $provider->id)
            ->whereIn('barangay_code', $barangays)
            ->operationalCoverage()
            ->pluck('station_hub_id', 'barangay_code');

        $originStationHubs = $shipments->getCollection()->mapWithKeys(function (SellerOrder $shipment) use ($stationByBarangay): array {
            $stationId = $shipment->origin_station_hub_id
                ?? $stationByBarangay[$shipment->sellerBarangayCode()] ?? null;

            return [$shipment->id => $stationId !== null ? (int) $stationId : null];
        });

        return view('Logistics.IncomingParcels.index', [
            'shipments' => $shipments,
            'provider' => $provider,
            'highlightedId' => $highlightedId,
            'originStationHubs' => $originStationHubs,
            'availableCouriers' => Courier::query()->where('logistics_provider_id', $provider->id)->eligibleForAssignment()->withWorkload()->withActiveWeight()->with('rider.station')->orderBy('rider_id')->get(),
        ]);
    }

    /** Retry the automatic pickup/delivery reservation after coverage or Rider availability changes. */
    public function retryDispatch(Request $request, SellerOrder $sellerOrder, string $role): RedirectResponse
    {
        $provider = $request->user('logistics');
        $this->authorizeForUser($provider, 'advance-shipment', $sellerOrder);

        $result = $this->assignment->dispatch($sellerOrder, $role, $provider);

        if ($role === 'pickup' && $result['status'] === 'reserved' && $result['courier'] !== null) {
            $this->assignment->confirmPickupDispatch($sellerOrder->fresh(), $provider);

            return back()->with('success', "Pickup rider {$result['courier']->name} reserved and notified.");
        }

        return back()->with(
            in_array($result['status'], ['assigned', 'reserved'], true) ? 'success' : 'error',
            $result['message'],
        );
    }

    /**
     * Reserve a PICKUP rider WITHOUT advancing the parcel.
     *
     * The Rider then confirms the collection from their own panel. The parcel
     * stays ReadyForPickup (and stays listed here) until the rider does, and
     * Logistics still has to confirm receipt at the origin Station Hub before
     * the parcel advances to sorting.
     *
     * If `reassign` is true, allows reassigning a parcel that already has a
     * pickup courier (releases the old courier back to available).
     */
    public function assignPickupRider(Request $request, SellerOrder $sellerOrder, LogisticsHubService $hubs): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if (($reason = $hubs->assignmentBlockReason($provider->id)) !== null) {
            return back()->with('error', $reason);
        }

        $reassign = $request->boolean('reassign');

        if ($sellerOrder->status !== OrderStatus::ReadyForPickup && ! ($reassign && $sellerOrder->status === OrderStatus::PickedUp)) {
            return back()->with('error', 'This parcel is no longer available for pickup assignment.');
        }

        if (! $reassign && $sellerOrder->courier_id !== null) {
            return back()->with('error', 'This parcel already has a pickup rider. Use reassignment to change.');
        }

        $data = $request->validate([
            'courier_id' => [$reassign ? 'nullable' : 'required', 'integer'],
            'reason' => [$reassign ? 'required' : 'nullable', 'string', 'max:500'],
            'expected_courier_id' => [$reassign ? 'required' : 'nullable', 'integer'],
        ], [
            'courier_id.required' => 'Select an eligible Rider for this pickup.',
        ]);

        $courier = $this->assignment->reservePickup(
            $sellerOrder,
            $provider,
            $request->integer('courier_id') ?: null,
            $reassign,
            $data['reason'] ?? null,
            isset($data['expected_courier_id']) ? (int) $data['expected_courier_id'] : null,
            notifyRider: false,
        );

        if ($courier === null) {
            return back()->with('error', 'No eligible rider in your fleet serves this pickup area.');
        }

        $action = $reassign ? 'reassigned' : 'assigned';

        $message = $sellerOrder->status === OrderStatus::PickedUp
            ? "Pickup {$action} to {$courier->name}. Awaiting Station Hub receipt."
            : "Pickup {$action} to {$courier->name}. Waiting for the rider to confirm collection.";

        return back()->with('success', $message);
    }

    /**
     * Confirm the reserved Rider's pickup dispatch. Reservation stays silent;
     * this is the single action that notifies the Rider (once per assignment).
     */
    public function confirmPickupDispatch(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        try {
            $result = $this->assignment->confirmPickupDispatch($sellerOrder, $provider);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result === null) {
            return back()->with('error', 'Assign an eligible pickup rider before confirming the dispatch.');
        }

        return back()->with(
            'success',
            $result['notified']
                ? 'Pickup dispatch confirmed. The assigned rider has been notified.'
                : 'Pickup dispatch was already confirmed for the assigned rider.',
        );
    }

    /**
     * Hand the parcel to the sorting center: advance to AtSortingCenter
     * and release the pickup courier back to available.
     */
    public function toSortingCenter(
        Request $request,
        SellerOrder $sellerOrder,
        ParcelHandoffVerification $handoffVerify,
        LogisticsHubService $hubs,
    ): RedirectResponse {
        $provider = $request->user('logistics');

        // Provider ownership before any idempotent success path.
        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        $sellerOrder->loadMissing(['courier.provider', 'seller']);
        $fresh = $sellerOrder->fresh() ?? $sellerOrder;
        $alreadyAtHub = $fresh->status === OrderStatus::AtSortingCenter;
        $token = $request->input('verification_token');

        if (! $alreadyAtHub && $fresh->status !== OrderStatus::PickedUp) {
            return back()->with('error', 'Scan blocked: this parcel must be picked up before it can be received at the hub.');
        }

        try {
            // Authoritative origin Station Hub (seller barangay coverage) — never guess.
            $stationHubId = $hubs->requireOriginStationHubId((int) $provider->id, $fresh);

            $method = null;
            if (! $alreadyAtHub) {
                $payload = $handoffVerify->assertValidForLogistics(
                    $provider,
                    $fresh,
                    ParcelHandoffVerification::ACTION_STATION_RECEIPT,
                    $token,
                    $stationHubId,
                );
                $method = $payload['method'] ?? null;

                $this->status->transition(
                    $fresh,
                    OrderStatus::AtSortingCenter,
                    note: 'Parcel handed to sorting center.'.ParcelHandoffVerification::noteSuffix($method),
                    changedBy: $provider->fullName(),
                    actor: OrderActor::Logistics,
                    actorId: $provider->id,
                    source: 'web',
                );

                $handoffVerify->consumeAfterSuccess(fn () => $handoffVerify->consumeForLogistics(
                    $provider,
                    $fresh,
                    ParcelHandoffVerification::ACTION_STATION_RECEIPT,
                    $token,
                    $stationHubId,
                ));
            }
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pickupCourier = $sellerOrder->fresh()?->courier;
        if ($pickupCourier) {
            $this->assignment->release($pickupCourier);
        }

        return back()->with('success', $alreadyAtHub
            ? 'Parcel already received at the hub. No status change was made.'
            : 'Parcel received at the hub. Pickup courier released.');
    }
}
