<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\BuyerShipmentConfirmation;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    /**
     * seller_orders.status buckets used by the buyer order-list filter pills.
     * Kept in one place so the pill counts and the applied filter can never drift.
     */
    private const TO_SHIP_STATUSES = [
        OrderStatus::Placed,
        OrderStatus::Confirmed,
        OrderStatus::Preparing,
        OrderStatus::ReadyForPickup,
    ];

    private const TO_RECEIVE_STATUSES = [
        OrderStatus::AssignedToRider,
        OrderStatus::PickedUp,
        OrderStatus::AtSortingCenter,
        OrderStatus::Sorted,
        OrderStatus::OutForDelivery,
        OrderStatus::Delivered,
    ];

    private const CANCELLED_STATUSES = [
        OrderStatus::Cancelled,
    ];

    public function __construct(
        private readonly BuyerShipmentConfirmation $confirmations,
    ) {}

    /**
     * List the authenticated buyer's orders with their current status.
     */
    public function index(Request $request): View
    {
        $buyer = $request->user('buyer');
        $tab = $request->query('tab', 'all');
        $search = trim((string) $request->query('search', ''));

        $baseQuery = Order::query()
            ->where('buyer_id', $buyer->id)
            ->with([
                'sellerOrders' => fn ($q) => $q
                    ->with('courier', 'seller', 'orderItems.product.images', 'parcelReturn')
                    ->orderBy('created_at'),
            ]);

        if ($search !== '') {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('sellerOrders.orderItems', function ($itemQ) use ($search) {
                        $itemQ->where('product_name', 'like', "%{$search}%");
                    });
            });
        }

        // Pill counts across ALL of this buyer's orders (unfiltered by tab/search).
        $allOrdersForCounts = Order::query()
            ->where('buyer_id', $buyer->id)
            ->with('sellerOrders:id,order_id,status')
            ->get(['id']);

        $counts = [
            'all' => $allOrdersForCounts->count(),
            'to_ship' => 0,
            'to_receive' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'delivery_failed' => 0,
            'returned' => 0,
        ];

        foreach ($allOrdersForCounts as $o) {
            $statuses = $o->sellerOrders->pluck('status');
            if ($o->computeRollupStatus() === OrderStatus::Completed) {
                $counts['completed']++;
            }
            if ($statuses->contains(fn ($s) => in_array($s, self::TO_RECEIVE_STATUSES, true))) {
                $counts['to_receive']++;
            }
            if ($statuses->contains(fn ($s) => in_array($s, self::TO_SHIP_STATUSES, true))) {
                $counts['to_ship']++;
            }
            if ($statuses->contains(fn ($s) => in_array($s, self::CANCELLED_STATUSES, true))) {
                $counts['cancelled']++;
            }
            if ($statuses->contains(OrderStatus::DeliveryFailed)) {
                $counts['delivery_failed']++;
            }
            if ($statuses->contains(OrderStatus::Returned)) {
                $counts['returned']++;
            }
        }

        // Apply tab filter if selected.
        match ($tab) {
            'to_ship' => $baseQuery->whereHas(
                'sellerOrders',
                fn ($q) => $q->whereIn('status', self::TO_SHIP_STATUSES)
            ),
            'to_receive' => $baseQuery->whereHas(
                'sellerOrders',
                fn ($q) => $q->whereIn('status', self::TO_RECEIVE_STATUSES)
            ),
            'completed' => $baseQuery
                ->whereHas('sellerOrders', fn ($q) => $q->where('status', OrderStatus::Completed))
                ->whereDoesntHave('sellerOrders', fn ($q) => $q->whereNotIn('status', [OrderStatus::Completed, OrderStatus::Returned, OrderStatus::Cancelled])),
            'delivery_failed' => $baseQuery->whereHas('sellerOrders', fn ($q) => $q->where('status', OrderStatus::DeliveryFailed)),
            'returned' => $baseQuery->whereHas('sellerOrders', fn ($q) => $q->where('status', OrderStatus::Returned)),
            'cancelled' => $baseQuery->whereHas(
                'sellerOrders',
                fn ($q) => $q->whereIn('status', self::CANCELLED_STATUSES)
            ),
            default => $baseQuery,
        };

        $orders = $baseQuery
            ->latest('created_at')
            ->paginate(10)
            ->withQueryString();

        return view('Buyer.Orders.index', [
            'buyer' => $buyer,
            'orders' => $orders,
            'tab' => $tab,
            'search' => $search,
            'counts' => $counts,
        ]);
    }

    /**
     * Show a single order with all its seller-order fulfilment timelines.
     */
    public function show(Order $order, Request $request, ParcelReturnService $returns): View
    {
        $buyer = $request->user('buyer');

        // Policy: a buyer may only view their own order (guard user passed
        // explicitly — multi-guard app).
        $this->authorizeForUser($buyer, 'view', $order);

        $order->load([
            'sellerOrders' => fn ($q) => $q
                ->with(
                    'seller',
                    'courier',
                    'orderItems.product',
                    'statusHistories',
                    'parcelHandoffs', 'parcelReturn.events',
                    'payment',
                    'manifestItems.manifest.originHub',
                    'manifestItems.manifest.destinationHub',
                    'manifestItems.manifest.provider',
                ),
        ]);

        return view('Buyer.Orders.show', [
            'order' => $order,
            'canConfirm' => $order->canBeConfirmed(),
            'returnableShipmentIds' => $order->sellerOrders->filter(fn (SellerOrder $parcel) => $returns->canBuyerRequest($parcel))->modelKeys(),
        ]);
    }

    public function requestReturn(Order $order, SellerOrder $sellerOrder, Request $request, ParcelReturnService $returns): RedirectResponse|JsonResponse
    {
        $buyer = $request->user('buyer');
        $this->authorizeForUser($buyer, 'view', $order);
        abort_unless((int) $sellerOrder->order_id === (int) $order->id, 404);
        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(ParcelReturnService::BUYER_REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:500'],
            'photos' => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'description.required' => 'Describe what happened in 10 to 500 characters.',
            'description.min' => 'Describe what happened in 10 to 500 characters.',
            'description.max' => 'Describe what happened in 10 to 500 characters.',
            'photos.required' => 'Add 1 to 5 JPG or PNG photos, up to 5 MB each.',
            'photos.min' => 'Add 1 to 5 JPG or PNG photos, up to 5 MB each.',
            'photos.max' => 'Add up to 5 photos.',
            'photos.*.image' => 'Photos must be JPG or PNG files.',
            'photos.*.mimes' => 'Photos must be JPG or PNG files.',
            'photos.*.max' => 'Each photo may not be larger than 5 MB.',
        ]);
        $record = $returns->requestByBuyer($sellerOrder, $buyer, $data['reason'], $data['description'] ?? null, $request->file('photos', []));

        return $request->expectsJson()
            ? response()->json(['id' => $record->id, 'status' => $record->status->value, 'reason' => $record->reason], $record->wasRecentlyCreated ? 201 : 200)
            : back()->with('success', 'Return request recorded. The Seller has been notified.');
    }

    /** Ask an admin to review a seller's rejection. Allowed once; the seller is locked out until then. */
    public function escalateReturn(Order $order, SellerOrder $sellerOrder, Request $request, ParcelReturnService $returns): RedirectResponse
    {
        $buyer = $request->user('buyer');
        $this->authorizeForUser($buyer, 'view', $order);
        abort_unless((int) $sellerOrder->order_id === (int) $order->id, 404);
        abort_unless($sellerOrder->parcelReturn !== null, 404);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'reason.required' => 'Tell the admin why this rejection should be reviewed.',
            'reason.min' => 'Tell the admin why this rejection should be reviewed, in 10 to 1000 characters.',
            'reason.max' => 'Tell the admin why this rejection should be reviewed, in 10 to 1000 characters.',
        ]);
        $returns->escalateByBuyer($sellerOrder->parcelReturn, $buyer, $data['reason']);

        return back()->with('success', 'An admin will review this rejection. The seller cannot change it until then.');
    }

    /** Confirm a selected delivered shipment, or all delivered shipments. */
    public function confirmReceived(Order $order, Request $request): RedirectResponse
    {
        $buyer = $request->user('buyer');
        $this->authorizeForUser($buyer, 'confirmReceived', $order);
        $data = $request->validate(['seller_order_id' => ['nullable', 'integer', 'min:1']]);
        $this->confirmations->confirm(
            $order, $buyer, isset($data['seller_order_id']) ? (int) $data['seller_order_id'] : null,
        );

        return back()->with('success', 'Order received.');
    }

    /**
     * Buyer cancels one seller_order slice before Ready for Pickup.
     * Stock/payment side effects run through the shared
     * status service (same path as F1 seller decline).
     */
    public function cancelSellerOrder(
        Order $order,
        SellerOrder $sellerOrder,
        Request $request,
    ): RedirectResponse|JsonResponse {
        $buyer = $request->user('buyer');
        $this->authorizeForUser($buyer, 'cancelSellerOrder', $order);

        if ((int) $sellerOrder->order_id !== (int) $order->id) {
            abort(404);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Please provide a cancellation reason.',
        ]);

        $service = new SellerOrderStatusService;

        try {
            DB::transaction(function () use ($sellerOrder, $service, $validated, $buyer, $order): void {
                // Serialize sibling cancellations before taking the shipment lock.
                Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $current = SellerOrder::query()
                    ->whereKey($sellerOrder->id)
                    ->lockForUpdate()
                    ->first();

                if ($current === null) {
                    abort(404);
                }

                if (! in_array($current->status, [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Preparing], true)) {
                    throw new \InvalidArgumentException(
                        'Immediate cancellation is unavailable once this shipment is ready for pickup.'
                    );
                }

                $service->transition(
                    $current,
                    OrderStatus::Cancelled,
                    note: 'Buyer cancelled: '.$validated['reason'],
                    changedBy: 'buyer',
                    actor: OrderActor::Buyer,
                    actorId: (int) $buyer->id,
                    source: 'web',
                    expectedFrom: $current->status,
                );
            }, 3);
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'status' => OrderStatus::Cancelled->value,
                'status_label' => OrderStatus::Cancelled->label(),
                'message' => 'Shipment cancelled. The seller has been notified.',
            ]);
        }

        return back()->with('success', 'Shipment cancelled. The seller has been notified.');
    }
}
