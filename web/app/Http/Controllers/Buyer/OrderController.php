<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\SellerOrderStatusService;
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
    ];

    public function __construct(
        private readonly SellerOrderStatusService $status = new SellerOrderStatusService,
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
            if ($statuses->contains(fn ($s) => $s === OrderStatus::Completed)) {
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
            'completed' => $baseQuery->whereHas(
                'sellerOrders',
                fn ($q) => $q->where('status', OrderStatus::Completed)
            ),
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
                ->with('courier', 'orderItems.product', 'statusHistories', 'payment'),
        ]);

        return view('Buyer.Orders.show', [
            'order' => $order,
            'canConfirm' => $order->canBeConfirmed(),
        ]);
    }

    /**
     * Buyer confirms receipt → each Delivered seller_order becomes Completed.
     *
     * Gate: canBeConfirmed() ensures every slice is Delivered or Completed with
     * at least one Delivered — the buyer cannot partially confirm an order that
     * still has slices in transit.
     */
    public function confirmReceived(Order $order, Request $request): RedirectResponse
    {
        $buyer = $request->user('buyer');

        // Another buyer's order → 404 (avoid leaking existence).
        abort_unless($order->buyer_id === $buyer->id, 404);

        // Policy: a buyer may only confirm receipt of their own order.
        $this->authorizeForUser($buyer, 'confirmReceived', $order);

        $order->load('sellerOrders');

        if (! $order->canBeConfirmed()) {
            return back()->with('error', 'There are no delivered items to confirm right now.');
        }

        $delivered = $order->sellerOrders
            ->where('status', OrderStatus::Delivered)
            ->values();

        // ONE transaction: either all Delivered slices advance to Completed or
        // none do. SellerOrderStatusService::transition() also runs the parent
        // rollup + COD settlement inside ITS transaction — the outer transaction
        // here is a savepoint, so a throw rolls everything back.
        return DB::transaction(function () use ($delivered, $buyer): RedirectResponse {
            foreach ($delivered as $sellerOrder) {
                $this->status->transition(
                    $sellerOrder,
                    OrderStatus::Completed,
                    note: 'Confirmed received by buyer.',
                    changedBy: $buyer->fullName(),
                    actor: OrderActor::Buyer,
                    actorId: $buyer->id,
                    source: 'web',
                );
            }

            return back()->with('success', 'Order marked as received. Thank you!');
        });
    }
}
