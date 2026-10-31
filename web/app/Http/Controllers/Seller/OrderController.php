<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Notifications\Logistics\PickupRequested;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Seller's order list, filterable by a broad status bucket via ?bucket=.
     * Buckets: to_ship | ready | shipped | delivered | cancelled. Anything
     * else falls back to "all". Tab counts ignore the active filter.
     */
    public function index(Request $request)
    {
        $seller = Auth::guard('seller')->user();
        $tabCounts = $this->tabCounts((int) $seller->id);
        $bucket = $request->query('bucket');
        $bucket = is_string($bucket) && array_key_exists($bucket, $tabCounts) ? $bucket : 'all';

        $query = SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->with([
                'order' => fn ($q) => $q->with('buyer'),
                'orderItems.product',
                'courier',
                'deliveryCourier',
            ]);
        $this->firstComeFirstServed($query);

        if ($bucket !== 'all') {
            $statusValues = collect(OrderStatus::cases())
                ->filter(fn (OrderStatus $s) => $s->bucket() === $bucket)
                ->map(fn (OrderStatus $s) => $s->value)
                ->all();

            $query->whereIn('status', $statusValues);
        }

        $sellerOrders = $query->paginate(15)->appends($request->except('page'));

        return view('Seller.Orders.index', [
            'sellerOrders' => $sellerOrders,
            'bucket' => $bucket,
            'tabCounts' => $tabCounts,
        ]);
    }

    /**
     * Oldest first within each stage, so no buyer is overtaken. Orders the seller
     * still has to prepare lead, then those on their way; finished ones sink, or
     * old history would keep pushing the work off the first page.
     */
    private function firstComeFirstServed(Builder $query): void
    {
        $prepare = [OrderStatus::Placed->value, OrderStatus::Confirmed->value, OrderStatus::Preparing->value];
        $finished = [OrderStatus::Delivered->value, OrderStatus::Completed->value, OrderStatus::Returned->value, OrderStatus::Cancelled->value];

        $query->orderByRaw(
            'CASE WHEN status IN (?, ?, ?) THEN 0 WHEN status IN (?, ?, ?, ?) THEN 2 ELSE 1 END',
            [...$prepare, ...$finished],
        )->orderBy('created_at')->orderBy('id');
    }

    /**
     * Number of this seller's orders in each tab.
     *
     * @return array<string, int>
     */
    private function tabCounts(int $sellerId): array
    {
        $counts = ['all' => 0, 'to_ship' => 0, 'ready' => 0, 'shipped' => 0, 'delivered' => 0, 'cancelled' => 0];

        $byStatus = SellerOrder::query()
            ->where('seller_id', $sellerId)
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($byStatus as $status => $count) {
            $counts['all'] += (int) $count;
            $counts[OrderStatus::from($status)->bucket()] += (int) $count;
        }

        return $counts;
    }

    /**
     * Order detail with a full status-history timeline and tracking info.
     */
    public function show(SellerOrder $sellerOrder)
    {
        $this->authorizeForUser(Auth::guard('seller')->user(), 'view', $sellerOrder);

        $sellerOrder->load([
            'order.buyer',
            'orderItems.product',
            'payment',
            'courier',
            'deliveryCourier',
            'statusHistories',
            'parcelHandoffs', 'parcelReturn.events',
            'manifestItems.manifest.originHub',
            'manifestItems.manifest.destinationHub',
            'manifestItems.manifest.provider',
        ]);

        return view('Seller.Orders.show', ['sellerOrder' => $sellerOrder]);
    }

    /** Accept a new order without implicitly starting the packing step. */
    public function acceptOrder(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        return $this->advanceFulfillment($sellerOrder, OrderStatus::Confirmed, 'Order accepted by seller.', 'accepted');
    }

    /** Start packing an accepted order before making it ready for pickup. */
    public function packOrder(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        return $this->advanceFulfillment($sellerOrder, OrderStatus::Preparing, 'Packing started by seller.', 'packed');
    }

    private function advanceFulfillment(SellerOrder $sellerOrder, OrderStatus $to, string $note, string $flash): RedirectResponse
    {
        $this->authorizeForUser(Auth::guard('seller')->user(), 'markReady', $sellerOrder);

        try {
            app(SellerOrderStatusService::class)->transition(
                $sellerOrder, $to, note: $note, changedBy: 'seller',
                actor: OrderActor::Seller, actorId: (int) Auth::guard('seller')->id(), source: 'web',
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($flash, true);
    }

    /**
     * Seller declines their slice of a multi-seller (or single-seller) order:
     * packaging statuses → CANCELLED, stock restored, pending payment voided,
     * voucher usage returned. Sibling seller_orders are untouched.
     */
    public function declineOrder(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $this->authorizeForUser(Auth::guard('seller')->user(), 'decline', $sellerOrder);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:300'],
        ], [
            'reason.required' => 'Enter a reason for cancelling this order.',
            'reason.min' => 'The cancellation reason must be at least 10 characters.',
            'reason.max' => 'The cancellation reason may not be more than 300 characters.',
        ]);

        $service = new SellerOrderStatusService;

        try {
            DB::transaction(function () use ($sellerOrder, $service, $validated): void {
                $current = SellerOrder::query()
                    ->whereKey($sellerOrder->id)
                    ->lockForUpdate()
                    ->first();

                if ($current === null) {
                    abort(404);
                }

                $declinable = in_array(
                    $current->status,
                    [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup],
                    true
                );

                if (! $declinable) {
                    throw new \InvalidArgumentException(
                        'Only orders that have not been picked up can be declined.'
                    );
                }

                $note = filled($validated['reason'] ?? null)
                    ? 'Seller declined: '.$validated['reason']
                    : 'Seller declined this order.';

                $service->transition(
                    $current,
                    OrderStatus::Cancelled,
                    note: $note,
                    changedBy: 'seller',
                    actor: OrderActor::Seller,
                    actorId: (int) Auth::guard('seller')->id(),
                    source: 'web',
                    expectedFrom: $current->status,
                );
            });
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('declined', true);
    }

    /**
     * Mark a seller_order as packed / ready for pickup.
     *
     * Moves the order to READY_FOR_PICKUP. NO courier is auto-assigned here —
     * the courier is assigned at pickup time by the rider, and the delivery
     * courier is assigned at the sorting center.
     *
     * Double-click safety: the row is re-read UNDER LOCK inside a transaction
     * before acting, and an already-ready order returns early.
     */
    public function markReady(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $this->authorizeForUser(Auth::guard('seller')->user(), 'markReady', $sellerOrder);

        $service = new SellerOrderStatusService;

        $alreadyHandled = false;

        try {
            $sellerOrder = DB::transaction(function () use ($sellerOrder, $service, &$alreadyHandled): SellerOrder {
                $current = SellerOrder::query()
                    ->whereKey($sellerOrder->id)
                    ->lockForUpdate()
                    ->first();

                if ($current === null) {
                    abort(404);
                }

                if ($current->status === OrderStatus::ReadyForPickup) {
                    $alreadyHandled = true;

                    return $current;
                }

                $service->transition(
                    $current,
                    OrderStatus::ReadyForPickup,
                    note: 'Packed by seller.',
                    changedBy: 'seller',
                    actor: OrderActor::Seller,
                    actorId: (int) Auth::guard('seller')->id(),
                    source: 'web',
                    expectedFrom: $current->status,
                );

                // Notify the selected logistics provider (via carrier_provider_id from checkout)
                if ($current->carrier_provider_id !== null) {
                    $provider = $current->carrierProvider->fresh();
                    if ($provider instanceof LogisticsProvider) {
                        $provider->notify(new PickupRequested(
                            $current->fresh(),
                            $current->carrier_provider_id
                        ));
                    }
                }

                return $current->fresh();
            });
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($alreadyHandled) {
            return back()->with('warning', 'This order is already marked ready for pickup — nothing more to do here.');
        }

        return back()->with('ready', true);
    }
}
