<?php

namespace App\Services;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use App\Notifications\Buyer\CourierAssigned;
use App\Notifications\Buyer\DeliveryFailed as BuyerDeliveryFailed;
use App\Notifications\Buyer\OrderDelivered;
use App\Notifications\Buyer\OrderOutForDelivery;
use App\Notifications\Seller\DeliveryFailed as SellerDeliveryFailed;
use App\Notifications\Seller\ParcelReturned;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for every seller_order status change.
 *
 * All pipeline transitions MUST go through transition() — never set
 * ->status directly — so the status_histories audit log stays complete,
 * every hop is validated against the legal state graph AND against the
 * acting role, and concurrent double-clicks cannot advance a row twice
 * (the current status is re-read UNDER LOCK inside the transaction and
 * compared against the caller's view — a compare-and-swap).
 */
class SellerOrderStatusService
{
    /**
     * Legal forward transitions. A status not present here as a key has no
     * outbound transitions (terminal). The graph is forward-only: every hop
     * must appear explicitly, so you cannot skip or reverse states.
     *
     * Role ownership of each hop lives in OrderActor::allowedTransitions().
     */
    private const ALLOWED = [
        OrderStatus::Placed->value => [OrderStatus::Confirmed, OrderStatus::ReadyForPickup],
        OrderStatus::Confirmed->value => [OrderStatus::Preparing],
        OrderStatus::Preparing->value => [OrderStatus::ReadyForPickup],
        OrderStatus::ReadyForPickup->value => [OrderStatus::PickedUp],
        OrderStatus::PickedUp->value => [OrderStatus::AtSortingCenter],
        OrderStatus::AtSortingCenter->value => [OrderStatus::Sorted],
        OrderStatus::Sorted->value => [OrderStatus::AssignedToRider],
        OrderStatus::AssignedToRider->value => [OrderStatus::OutForDelivery],
        OrderStatus::OutForDelivery->value => [OrderStatus::Delivered, OrderStatus::DeliveryFailed],
        OrderStatus::Delivered->value => [OrderStatus::Completed],
        OrderStatus::DeliveryFailed->value => [OrderStatus::AssignedToRider, OrderStatus::Returned],
        OrderStatus::Returned->value => [],
        OrderStatus::Completed->value => [],
    ];

    /**
     * Transition a seller_order to $to, validating the move is legal for the
     * acting role, persisting it, and recording a status_histories row.
     *
     * Concurrency (compare-and-swap under lock): the row is re-read with
     * lockForUpdate INSIDE the transaction and every gate runs against that
     * TRUE current status, never the caller's possibly-stale copy:
     *
     *   - row already at $to        → idempotent replay (a double-click whose
     *                                 first click landed): no-op, NO history row;
     *   - $to legal from the true   → proceeds (a sequential caller whose
     *     current status              in-memory model lags one hop still works);
     *   - anything else             → rejected: no skip, no reverse, no
     *                                 duplicate history row, no two-hop advance.
     *
     * @param  OrderActor|null  $actor  WHO performs the hop; null skips the
     *                                  role check (legacy direct-service calls,
     *                                  transitional until every caller passes one).
     * @param  int|null  $actorId  Row id of the acting seller/logistics/rider/buyer.
     * @param  string|null  $source  web | simulator | api | system.
     * @param  OrderStatus|null  $expectedFrom  What the CALLER believed the status
     *                                          to be ("what the page showed") —
     *                                          informational; the authoritative
     *                                          comparison happens under the lock.
     *
     * @throws \InvalidArgumentException when the transition is not allowed for
     *                                   this actor or is not in the graph.
     */
    public function transition(
        SellerOrder $sellerOrder,
        OrderStatus $to,
        ?string $note = null,
        ?string $changedBy = null,
        ?OrderActor $actor = null,
        ?int $actorId = null,
        ?string $source = null,
        ?OrderStatus $expectedFrom = null,
    ): SellerOrder {
        $from = $expectedFrom ?? $sellerOrder->status;

        if ($from === $to) {
            return $sellerOrder;
        }

        $sellerOrder = DB::transaction(function () use ($sellerOrder, $from, $to, $note, $changedBy, $actor, $actorId, $source): SellerOrder {
            // Compare-and-swap reference: the CURRENT status under the row lock.
            $current = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Order #%d no longer exists.',
                    $sellerOrder->id,
                ));
            }

            $currentStatus = $current->status;

            // Idempotent replay: a concurrent request (double-click) already
            // performed exactly this hop. Do nothing, record nothing.
            if ($currentStatus === $to) {
                return $current;
            }

            // Role gate — against the TRUE current status, not the caller's view.
            if ($actor !== null && ! $actor->canTransition($currentStatus, $to)) {
                throw new \InvalidArgumentException(sprintf(
                    'The %s role cannot move an order from [%s] to [%s].',
                    $actor->value,
                    $currentStatus->value,
                    $to->value,
                ));
            }

            // Graph gate — forward-only, no skips. A caller whose view went
            // stale can only proceed when its intended hop is STILL legal.
            $allowed = self::ALLOWED[$currentStatus->value] ?? [];

            if (! in_array($to, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Order #%d changed while you were working on it: it is now [%s], so it cannot go to [%s]%s. Refresh and try again.',
                    $sellerOrder->id,
                    $currentStatus->value,
                    $to->value,
                    $from !== $currentStatus ? sprintf(' (your page showed [%s])', $from->value) : '',
                ));
            }

            $update = ['status' => $to->value];

            // A real shipment gets a tracking number the moment it leaves the
            // depot for pickup (becomes the courier's responsibility).
            if ($to === OrderStatus::PickedUp && ! $current->tracking_number) {
                $update['tracking_number'] = SellerOrder::generateUniqueTrackingNumber();
            }

            $current->forceFill($update)->save();

            // The audit log records the TRUE from-status, never the stale view.
            StatusHistory::record($current, $currentStatus, $to, $changedBy, $note, $actor, $actorId, $source);

            // ── Parent rollup + COD settlement (INSIDE the transaction) ──────
            // These must be transactional: if the rollup or settlement throws,
            // the seller_order status change above is rolled back too.
            // Row-lock the parent orders row and re-query ALL its seller_orders
            // fresh from the DB so the rollup sees concurrent sibling changes.
            $this->rollUpParentOrder($current);

            // COD is settled when cash is collected at the door (Delivered) and
            // when the buyer confirms (Completed). Idempotent: pending-only,
            // so Deliv­ered→settles then Completed→already-paid→skip.
            if (in_array($to, [OrderStatus::Delivered, OrderStatus::Completed], true)) {
                $this->settleCodPayment($current);
            }

            // A parcel that reaches RETURNED goes back to the seller's shelf,
            // so its stock is restored (reverse of the checkout decrement).
            if ($to === OrderStatus::Returned) {
                $this->restoreStock($current);
            }

            return $current->fresh();
        });

        // Fire buyer-facing notifications for key milestones (synchronously,
        // null-safe so a seller_order without a linked buyer/order never errors).
        // Skipped on the idempotent-replay no-op (the first request notified).
        if ($sellerOrder->status === $to) {
            $this->maybeNotify($sellerOrder, $to);

            // Release couriers at the appropriate pipeline hand-offs.
            $this->releaseCouriers($sellerOrder, $to);
        }

        return $sellerOrder;
    }

    /**
     * Release couriers when their run concludes:
     *  - AtSortingCenter → the pickup courier is done (parcel is now at the hub).
     *  - Delivered / DeliveryFailed → the delivery courier is done.
     * Idempotent: setting is_available = true twice is harmless.
     */
    private function releaseCouriers(SellerOrder $sellerOrder, OrderStatus $to): void
    {
        if ($to === OrderStatus::AtSortingCenter) {
            $pickupCourier = $sellerOrder->courier;
            if ($pickupCourier) {
                $pickupCourier->forceFill(['is_available' => true])->save();
            }
        }

        if (in_array($to, [OrderStatus::Delivered, OrderStatus::DeliveryFailed], true)) {
            $deliveryCourier = $sellerOrder->deliveryCourier;
            if ($deliveryCourier) {
                $deliveryCourier->forceFill(['is_available' => true])->save();
            }
        }
    }

    /**
     * Send in-app notifications for buyer-relevant status milestones.
     * Guarded by optional relationships so tests that seed bare SellerOrders
     * don't crash.
     */
    private function maybeNotify(SellerOrder $sellerOrder, OrderStatus $to): void
    {
        // Buyer-facing milestones.
        $buyer = $sellerOrder->order?->buyer;

        if ($buyer) {
            match ($to) {
                OrderStatus::AssignedToRider => $buyer->notify(new CourierAssigned($sellerOrder)),
                OrderStatus::OutForDelivery => $buyer->notify(new OrderOutForDelivery($sellerOrder)),
                OrderStatus::Delivered => $buyer->notify(new OrderDelivered($sellerOrder)),
                OrderStatus::DeliveryFailed => $buyer->notify(new BuyerDeliveryFailed($sellerOrder)),
                default => null,
            };
        }

        // Seller-facing milestones: a failed attempt and a return both need
        // the shop's attention.
        $seller = $sellerOrder->seller;

        if ($seller) {
            if ($to === OrderStatus::DeliveryFailed) {
                $seller->notify(new SellerDeliveryFailed($sellerOrder));
            }

            if ($to === OrderStatus::Returned) {
                $seller->notify(new ParcelReturned($sellerOrder));
            }
        }
    }

    /**
     * Put a returned parcel's stock back on the shelf and log it in the
     * append-only inventory ledger. Runs inside the transition transaction.
     */
    private function restoreStock(SellerOrder $sellerOrder): void
    {
        $sellerOrder->loadMissing('orderItems.variant');

        foreach ($sellerOrder->orderItems as $item) {
            $variant = $item->variant;

            if ($variant === null) {
                continue;
            }

            $before = (int) $variant->stock_quantity;
            $variant->stock_quantity = $before + (int) $item->quantity;
            $variant->save();

            InventoryTransaction::query()->create([
                'product_id' => $variant->product_id,
                'seller_order_id' => $sellerOrder->id,
                'variant_id' => $variant->id,
                'quantity_before' => $before,
                'quantity_after' => $variant->stock_quantity,
                'delta' => (int) $item->quantity,
                'reason' => 'return',
            ]);
        }

        Product::syncRollupsForProducts(
            $sellerOrder->orderItems->pluck('product_id')->unique()->values(),
        );
    }

    /**
     * Re-derive the parent order's status from ALL its seller_orders.
     *
     * Called INSIDE the transition's DB transaction. The parent orders row
     * is locked with lockForUpdate and every sibling seller_order is
     * re-queried fresh from the DB, so the rollup always reflects the true
     * current state of the whole order — not a stale in-memory snapshot.
     *
     * Pipeline rank in OrderStatus::cases() declaration order:
     *   Placed=0 → … → Delivered=9 → Completed=10 → DeliveryFailed=11 → Returned=12
     *
     * DELIVERY_FAILED (rank 11) and RETURNED (rank 12) sit AFTER Completed
     * (rank 10) on the enum's declaration order. A problem status is never
     * masked by a neighbour's progress: if one slice is Completed (10) and
     * another is DeliveryFailed (11), the min-rank is 10 (Completed), which
     * is correct — the failure slice is still in flight, not terminal.
     */
    private function rollUpParentOrder(SellerOrder $sellerOrder): void
    {
        if ($sellerOrder->order_id === null) {
            return;
        }

        $order = Order::query()
            ->whereKey($sellerOrder->order_id)
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            return;
        }

        $order->load('sellerOrders');
        $order->rollUpStatus();
    }

    /**
     * Settle the COD payment for a seller_order that just reached Delivered
     * or Completed.
     *
     * COD = Cash On Delivery: the buyer hands cash to the rider at the door,
     * so the payment is marked Paid when the seller_order enters Delivered
     * (cash collected) — and re-checked at Completed (buyer confirmed) as a
     * safety net. Idempotent: only Pending payments are touched, so
     * Delivered→settles then Completed→already-paid→skip. Non-COD methods and
     * missing payment rows are skipped without error.
     */
    private function settleCodPayment(SellerOrder $sellerOrder): void
    {
        $sellerOrder->load('payment');

        if ($sellerOrder->payment
            && $sellerOrder->payment->method === PaymentMethod::Cod
            && $sellerOrder->payment->status === PaymentStatus::Pending
        ) {
            $sellerOrder->payment->markPaid();
        }
    }
}
