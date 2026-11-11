<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Exceptions\StaleStateException;
use App\Http\Controllers\Controller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\BuyerOrderCancellation;
use App\Services\Buyer\BuyerShipmentConfirmation;
use App\Services\Buyer\ProductReviewService;
use App\Services\Logistics\DeliveryOtpService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Logistics\RouteExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        OrderStatus::InTransit,
        OrderStatus::ArrivedAtHub,
        OrderStatus::OutForDelivery,
        OrderStatus::Delivered,
    ];

    private const CANCELLED_STATUSES = [
        OrderStatus::Cancelled,
        OrderStatus::Lost,
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
        if (! in_array($tab, ['all', 'to_ship', 'to_receive', 'completed', 'cancelled', 'delivery_failed', 'returned'], true)) {
            $tab = 'all';
        }
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
            'routeDetails' => $order->sellerOrders->mapWithKeys(fn (SellerOrder $parcel): array => [
                $parcel->id => app(RouteExecutionService::class)->trackingSummary($parcel),
            ]),
            'deliveryCodes' => $order->sellerOrders->mapWithKeys(fn (SellerOrder $parcel): array => [
                $parcel->id => app(DeliveryOtpService::class)->activeCodeFor($parcel),
            ]),
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

    /** The buyer withdraws a return before a rider collects it; the item stays with them. */
    public function cancelReturn(Order $order, SellerOrder $sellerOrder, Request $request, ParcelReturnService $returns): RedirectResponse
    {
        $buyer = $request->user('buyer');
        $this->authorizeForUser($buyer, 'view', $order);
        abort_unless((int) $sellerOrder->order_id === (int) $order->id, 404);
        abort_unless($sellerOrder->parcelReturn !== null, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']], [
            'reason.max' => 'Keep the reason to 500 characters or fewer.',
        ]);
        $returns->cancelByBuyer($sellerOrder->parcelReturn, $buyer, $data['reason'] ?? null);

        return back()->with('success', 'Return cancelled. You keep the item, and you can confirm receipt of this shipment.');
    }

    /** Show editable review forms for eligible purchases in this order's confirmed scope. */
    public function review(Order $order, Request $request, ProductReviewService $reviews): View
    {
        $buyer = $request->user('buyer');
        $this->authorizeForUser($buyer, 'view', $order);
        $data = $request->validate(['seller_order_id' => ['nullable', 'integer', 'min:1']]);
        $shipmentId = isset($data['seller_order_id']) ? (int) $data['seller_order_id'] : null;
        $shipments = $order->sellerOrders()
            ->when($shipmentId !== null, fn ($query) => $query->whereKey($shipmentId))
            ->with('orderItems.product')->get();
        abort_if($shipmentId !== null && $shipments->isEmpty(), 404);

        $items = $shipments->flatMap(fn ($shipment) => $shipment->orderItems)
            ->filter(fn ($item) => $item->product !== null && ! $item->product->trashed());
        $eligibleIds = collect();
        foreach ($items->groupBy('product_id') as $productItems) {
            $eligibleIds = $eligibleIds->merge(
                $reviews->reviewableItems($productItems->first()->product, $buyer)->pluck('id'),
            );
        }
        $eligibleIds = $eligibleIds->flip();
        $reviewItems = $items->filter(fn ($item) => $eligibleIds->has($item->id))->values();

        return view('Buyer.Orders.review', compact('order', 'reviewItems'));
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

        return to_route('buyer.orders.review', array_filter([
            'order' => $order,
            'seller_order_id' => $data['seller_order_id'] ?? null,
        ]))->with('success', 'Order received.');
    }

    /**
     * Buyer cancels one seller_order slice before the seller accepts it.
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

        try {
            app(BuyerOrderCancellation::class)->cancel($order, $sellerOrder, $buyer, $validated['reason']);
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                if (! $e instanceof StaleStateException) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }

                // Let the page swap the stale Cancel button for the real status.
                $status = $sellerOrder->fresh()->status;

                return response()->json([
                    'message' => $e->getMessage(),
                    'status' => $status->value,
                    'status_label' => $status->label(),
                ], 409);
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
