<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SellerOrder;
use App\Services\BuyerShipmentConfirmation;
use App\Services\SellerOrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        OrderStatus::DeliveryFailed,
        OrderStatus::Returned,
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
                    ->with('courier', 'seller', 'orderItems.product.images')
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
        ];

        foreach ($allOrdersForCounts as $o) {
            $statuses = $o->sellerOrders->pluck('status');
            if ($o->computeRollupStatus() === OrderStatus::Completed) {
                $counts['completed']++;
            } elseif ($statuses->contains(fn ($s) => in_array($s, self::TO_RECEIVE_STATUSES, true))) {
                $counts['to_receive']++;
            } elseif ($statuses->contains(fn ($s) => in_array($s, self::TO_SHIP_STATUSES, true))) {
                $counts['to_ship']++;
            } elseif ($statuses->contains(fn ($s) => in_array($s, self::CANCELLED_STATUSES, true))) {
                $counts['cancelled']++;
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
    public function show(Order $order, Request $request): View
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
        ]);
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

        return back()->with('success', 'Delivered shipment confirmed. Thank you!');
    }

    /**
     * Buyer cancels one seller_order slice while it is still PLACED (before
     * the seller accepts). Stock/payment side effects run through the shared
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
        ]);

        $service = new SellerOrderStatusService;

        try {
            DB::transaction(function () use ($sellerOrder, $service, $validated, $buyer): void {
                $current = SellerOrder::query()
                    ->whereKey($sellerOrder->id)
                    ->lockForUpdate()
                    ->first();

                if ($current === null) {
                    abort(404);
                }

                if ($current->status !== OrderStatus::Placed) {
                    throw new \InvalidArgumentException(
                        'This shipment can no longer be cancelled — the seller has already started preparing it.'
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
                    expectedFrom: OrderStatus::Placed,
                );
            });
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
