<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            ->heldBy((int) $provider->id);

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

        // The immutable destination snapshot is authoritative; never use the buyer's live address.
        $grammar = DB::connection()->getQueryGrammar();
        $regionParts = collect(['region_code', 'province_code', 'municipality_code', 'barangay_code'])
            ->map(fn (string $key) => 'NULLIF(SUBSTRING(TRIM('.$grammar->wrap('orders.shipping_address->'.$key)."), 1, 2), '')")
            ->implode(', ');
        $destinationRegion = Order::query()
            ->selectRaw("COALESCE({$regionParts}, 'zz')")
            ->whereColumn('orders.id', 'seller_orders.order_id');
        $shipments = (clone $baseQuery)
            ->select('seller_orders.*')
            ->selectSub($destinationRegion, 'destination_region_sort')
            ->whereIn('status', $statuses)
            ->with([
                'deliveryCourier' => fn ($q) => $q->withWorkload(),
                'deliveryAssignments.courier',
                'originStation',
                'deliveryCourier.rider.station',
                'courier.provider',
                'deliveryCourier.provider',
                'order.buyer',
                'seller',
                'orderItems.product',
            ])
            ->orderBy('destination_region_sort')->orderBy('created_at')->orderBy('id')
            ->paginate(25)->withQueryString();

        return view('Logistics.ParcelSorting.index', [
            'shipments' => $shipments,
            'tab' => $tab,
            'sortingCount' => $sortingCount,
            'assignedCount' => $assignedCount,
            'areas' => Area::query()
                ->where('logistics_provider_id', $provider->id)
                ->orderBy('name')
                ->get(),
            'availableCouriers' => Courier::query()
                ->where('logistics_provider_id', $provider->id)
                ->eligibleForAssignment()->withWorkload()->withActiveWeight()
                ->with(['area', 'rider'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function previewAddress(Request $request, SellerOrder $sellerOrder, LogisticsHubService $hubs): View
    {
        $provider = $request->user('logistics');
        $resolved = $hubs->previewLegacyDeliveryAddress($provider, $sellerOrder);

        return view('Logistics.ParcelSorting.verify-address', ['parcel' => $sellerOrder, 'resolved' => $resolved]);
    }

    public function verifyAddress(Request $request, SellerOrder $sellerOrder, LogisticsHubService $hubs): RedirectResponse
    {
        $this->authorizeForUser($request->user('logistics'), 'remediate-shipment-address', $sellerOrder);
        $data = $request->validate([
            'snapshot_hash' => ['required', 'string', 'size:64'],
            'barangay_code' => ['required', 'string', 'max:20'],
            'evidence' => ['required', 'string', 'max:1000'],
            'confirmed' => ['accepted'],
        ]);
        $hubs->confirmLegacyDeliveryAddress($request->user('logistics'), $sellerOrder, $data['snapshot_hash'], $data['barangay_code'], $data['evidence']);

        return redirect()->route('logistics.parcel-sorting.index')->with('success', 'Original address evidence verified and destination coverage reconciled. The shipping snapshot and parcel status are unchanged.');
    }

    /**
     * Sort a parcel: AtSortingCenter → Sorted.
     *
     * Sorting is also when the parcel's DESTINATION AREA is determined: the
     * buyer's municipality is matched against this provider's active areas so
     * a rider from that area can be assigned. No match is not fatal — the
     * parcel still sorts and logistics can pick a rider by hand.
     */
    public function sort(
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
        $alreadySorted = $fresh->status === OrderStatus::Sorted;
        $token = $request->input('verification_token');

        if (! $alreadySorted && $fresh->status !== OrderStatus::AtSortingCenter) {
            return back()->with('error', 'This parcel has already been sorted.');
        }

        $area = $this->destinationAreaFor($provider, $fresh);

        try {
            // Authoritative origin Station Hub — required even for authorized retries.
            $stationHubId = $hubs->sortingHubId((int) $provider->id, $fresh);
            if ($stationHubId === null) {
                throw ValidationException::withMessages(['station_hub' => 'This parcel has no authorized sorting hub. Resolve custody routing before sorting.']);
            }

            $method = null;
            if (! $alreadySorted) {
                $payload = $handoffVerify->assertValidForLogistics(
                    $provider,
                    $fresh,
                    ParcelHandoffVerification::ACTION_SORT,
                    $token,
                    $stationHubId,
                );
                $method = $payload['method'] ?? null;
            }

            DB::transaction(function () use ($fresh, $provider, $area, $method, $alreadySorted): void {
                $fresh = SellerOrder::query()->whereKey($fresh->id)->lockForUpdate()->firstOrFail();
                ParcelIncidentService::assertOperationsAllowed($fresh);
                if ($alreadySorted) {
                    return;
                }

                if ($area !== null && $fresh->destination_area_id !== $area->id) {
                    $fresh->forceFill(['destination_area_id' => $area->id])->save();
                }

                $base = $area !== null
                    ? "Parcel sorted and routed to {$area->name}."
                    : 'Parcel sorted and routed for delivery.';

                $this->status->transition(
                    $fresh,
                    OrderStatus::Sorted,
                    note: $base.ParcelHandoffVerification::noteSuffix($method),
                    changedBy: $provider->fullName(),
                    actor: OrderActor::Logistics,
                    actorId: $provider->id,
                    source: 'web',
                );
            });

            if (! $alreadySorted) {
                $handoffVerify->consumeAfterSuccess(fn () => $handoffVerify->consumeForLogistics(
                    $provider,
                    $fresh,
                    ParcelHandoffVerification::ACTION_SORT,
                    $token,
                    $stationHubId,
                ));
            }
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $area !== null
            ? "Parcel sorted for {$area->name}."
            : 'Parcel sorted. New assignment is blocked: verify the original shipping-address barangay and configure validated coverage in an approved active Station Hub.');
    }

    /**
     * Assign a delivery courier: Sorted → AssignedToRider (sets
     * delivery_courier_id). Logistics may confirm the area-suggested rider or
     * change it by passing ?courier_id=.
     */
    public function assignDelivery(Request $request, SellerOrder $sellerOrder, LogisticsHubService $hubs): RedirectResponse
    {
        $provider = $request->user('logistics');

        $this->authorizeForUser($provider, 'advance-shipment', [$sellerOrder]);

        if (($reason = $hubs->assignmentBlockReason($provider->id)) !== null) {
            return back()->with('error', $reason);
        }

        if ($sellerOrder->status !== OrderStatus::Sorted) {
            return back()->with('error', 'This parcel must be sorted first.');
        }

        $preferredCourierId = $request->integer('courier_id') ?: null;
        $request->validate(['courier_id' => ['nullable', 'integer']]);

        try {
            $courier = $this->assignment->assignDelivery(
                $sellerOrder,
                $provider,
                $preferredCourierId,
                $sellerOrder->destination_area_id,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($courier === null) {
            return back()->with('error', 'New assignment is blocked. Verify the original delivery barangay, configure its active Station Hub coverage, and select an eligible available rider from that station.');
        }

        return back()->with('success', "Delivery courier {$courier->name} assigned.");
    }

    /**
     * Find this provider's active area covering the parcel's destination
     * municipality (case-insensitive). Null when nothing matches.
     */
    private function destinationAreaFor(LogisticsProvider $provider, SellerOrder $sellerOrder): ?Area
    {
        return (new LogisticsHubService)->areaForBarangay($provider->id, $sellerOrder->buyerBarangayCode());
    }
}
