<?php

namespace App\Services;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use App\Notifications\Buyer\CourierAssigned;
use App\Notifications\Buyer\DeliveryFailed as BuyerDeliveryFailed;
use App\Notifications\Buyer\OrderDelivered;
use App\Notifications\Buyer\OrderOutForDelivery;
use App\Notifications\Buyer\SellerOrderCancelled;
use App\Notifications\Seller\BuyerOrderCancelled;
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
    /** Two total delivery attempts: the initial run and one retry. */
    public const MAX_DELIVERY_ATTEMPTS = 2;

    public function __construct(
        private readonly CommissionFinalizationService $commissions = new CommissionFinalizationService,
    ) {}

    public static function canRetryDelivery(SellerOrder $parcel): bool
    {
        return ! $parcel->hasActiveReturn() && $parcel->status === OrderStatus::DeliveryFailed
            && (int) $parcel->delivery_attempts === self::MAX_DELIVERY_ATTEMPTS - 1;
    }

    /**
     * Legal forward transitions. A status not present here as a key has no
     * outbound transitions (terminal). The graph is forward-only: every hop
     * must appear explicitly, so you cannot skip or reverse states.
     *
     * Role ownership of each hop lives in OrderActor::allowedTransitions().
     */
    private const ALLOWED = [
        OrderStatus::Placed->value => [OrderStatus::Confirmed, OrderStatus::ReadyForPickup, OrderStatus::Cancelled],
        OrderStatus::Confirmed->value => [OrderStatus::Preparing, OrderStatus::Cancelled],
        OrderStatus::Preparing->value => [OrderStatus::ReadyForPickup, OrderStatus::Cancelled],
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
        OrderStatus::Cancelled->value => [],
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
        ParcelIncidentService::assertOperationsAllowed($sellerOrder);

        $from = $expectedFrom ?? $sellerOrder->status;

        if ($from === $to) {
            return $sellerOrder;
        }

        // True only when this call performed the hop under the lock. Idempotent
        // replays (already at $to) must not re-notify or re-release couriers.
        $didTransition = false;

        $sellerOrder = DB::transaction(function () use ($sellerOrder, $from, $to, $note, $changedBy, $actor, $actorId, $source, &$didTransition): SellerOrder {
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

            ParcelIncidentService::assertOperationsAllowed($current);

            $currentStatus = $current->status;
            if ($to === OrderStatus::Returned) {
                $proof = $current->parcelReturn;
                if ($proof === null || $proof->status !== ParcelReturnStatus::Completed || $proof->seller_received_at === null
                    || $actor !== OrderActor::Seller || (int) $actorId !== (int) $proof->received_by_seller_id
                    || (int) $actorId !== (int) $current->seller_id) {
                    throw new \InvalidArgumentException('Only verified physical Seller receipt can finalize a return.');
                }
            }
            if ($current->hasActiveReturn() && in_array($to, [OrderStatus::AssignedToRider, OrderStatus::OutForDelivery, OrderStatus::Delivered], true)) {
                throw new \InvalidArgumentException('This parcel is in the physical return workflow.');
            }

            if ($current->custody_provider_id !== null && $actor === null) {
                throw new \InvalidArgumentException('An authorized actor is required to process a parcel in provider custody.');
            }

            // A transferred parcel cannot be advanced by its original carrier,
            // including direct service calls with a stale model or scan token.
            if ($current->custody_provider_id !== null && $actor === OrderActor::Logistics
                && (int) $actorId !== $current->currentCustodyProviderId()) {
                throw new \InvalidArgumentException('Only the current custody provider may process this parcel.');
            }
            if ($current->custody_provider_id !== null && $actor === OrderActor::Rider) {
                $assigned = $current->deliveryCourier;
                if ($assigned === null || (int) $assigned->id !== (int) $actorId
                    || (int) $assigned->logistics_provider_id !== $current->currentCustodyProviderId()) {
                    throw new \InvalidArgumentException('Only the current custody provider\'s assigned Rider may process this parcel.');
                }
            }

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

            if (in_array($to, [OrderStatus::AssignedToRider, OrderStatus::OutForDelivery, OrderStatus::Delivered, OrderStatus::DeliveryFailed], true)
                && ((int) $current->delivery_attempts >= self::MAX_DELIVERY_ATTEMPTS
                    || ($currentStatus === OrderStatus::DeliveryFailed && ! self::canRetryDelivery($current)))) {
                throw new \InvalidArgumentException('Delivery is limited to two total attempts. Review the failure history or return the parcel to the seller.');
            }

            if ($actor === OrderActor::System && $to === OrderStatus::Completed
                && ! AutomaticBuyerReceiptService::eligibleOrders(now()->subHours(AutomaticBuyerReceiptService::WAIT_HOURS))
                    ->whereKey($current->getKey())->exists()) {
                throw new \InvalidArgumentException('Automatic receipt completion requires 72 hours since delivery and no unresolved parcel issues.');
            }

            $update = ['status' => $to->value];
            if ($to === OrderStatus::PickedUp && $current->pickup_address_snapshot === null) {
                $update['pickup_address_snapshot'] = ['full' => $current->pickupAddress(), 'barangay_code' => $current->sellerBarangayCode(), 'province_code' => $current->seller?->province_code];
            }
            if ($to === OrderStatus::DeliveryFailed) {
                $update['delivery_attempts'] = (int) $current->delivery_attempts + 1;
            }

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
            // Automatic receipt completion never collects or settles a payment.
            if ($to === OrderStatus::Delivered || ($to === OrderStatus::Completed && $actor !== OrderActor::System)) {
                $this->settleCodPayment($current);
            }

            if ($to === OrderStatus::Completed) {
                $this->commissions->finalize($current);
            }

            // A parcel that reaches RETURNED or CANCELLED goes back to the
            // seller's shelf (reverse of the checkout decrement).
            if ($to === OrderStatus::Returned) {
                $proof = $current->parcelReturn()->lockForUpdate()->firstOrFail();
                if (config('returns.restore_verified_resellable') === true && $proof->stock_condition === 'resellable' && $proof->stock_restored_at === null) {
                    if ($this->restoreStock($current, 'return')) {
                        $proof->forceFill(['stock_restored_at' => now()])->save();
                    }
                }
            }

            if ($to === OrderStatus::Cancelled) {
                $this->restoreStock($current, 'cancel');
                $this->cancelPendingPayment($current);
            }

            app(ParcelReturnService::class)->ensureAfterSecondFailure($current, $actor, $actorId);

            $didTransition = true;

            return $current->fresh();
        });

        // Notify / release only when THIS call advanced the row. Matching
        // post-tx status alone re-fires on concurrent idempotent replays.
        if ($didTransition) {
            $this->maybeNotify($sellerOrder, $to, $actor);

            // Release couriers at the appropriate pipeline hand-offs.
            $this->releaseCouriers($sellerOrder, $to);
            if (in_array($to, [OrderStatus::ReadyForPickup, OrderStatus::Sorted], true)) {
                app(CourierAssignmentService::class)->dispatchAfterCommit($sellerOrder);
            }
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
     * Send in-app notifications for buyer/seller status milestones.
     * Guarded by optional relationships so tests that seed bare SellerOrders
     * don't crash. Cancellation notifies the counterparty of the actor.
     */
    private function maybeNotify(SellerOrder $sellerOrder, OrderStatus $to, ?OrderActor $actor = null): void
    {
        // Buyer-facing milestones.
        $buyer = $sellerOrder->order?->buyer;

        if ($buyer) {
            match ($to) {
                OrderStatus::AssignedToRider => $buyer->notify(new CourierAssigned($sellerOrder)),
                OrderStatus::OutForDelivery => $buyer->notify(new OrderOutForDelivery($sellerOrder)),
                OrderStatus::Delivered => $buyer->notify(new OrderDelivered($sellerOrder)),
                OrderStatus::DeliveryFailed => $buyer->notify(new BuyerDeliveryFailed($sellerOrder)),
                // Seller declined → tell the buyer (F1). Buyer cancel notifies the seller below.
                OrderStatus::Cancelled => $actor !== OrderActor::Buyer
                    ? $buyer->notify(new SellerOrderCancelled($sellerOrder))
                    : null,
                default => null,
            };
        }

        // Seller-facing milestones.
        $seller = $sellerOrder->seller;

        if ($seller) {
            if ($to === OrderStatus::DeliveryFailed) {
                $seller->notify(new SellerDeliveryFailed($sellerOrder));
            }

            if ($to === OrderStatus::Returned) {
                $seller->notify(new ParcelReturned($sellerOrder));
            }

            if ($to === OrderStatus::Cancelled && $actor === OrderActor::Buyer) {
                $seller->notify(new BuyerOrderCancelled($sellerOrder));
            }
        }
    }

    /**
     * Put a returned/cancelled parcel's stock back on the shelf and log it in
     * the append-only inventory ledger. Runs inside the transition transaction.
     *
     * @param  'return'|'cancel'  $reason
     */
    public function releaseVerifiedReturnStock(SellerOrder $parcel, Admin $admin): void
    {
        app(ParcelReturnService::class)->admin($admin);
        DB::transaction(function () use ($parcel): void {
            $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            $proof = $parcel->parcelReturn()->lockForUpdate()->firstOrFail();
            if ($parcel->status !== OrderStatus::Returned || $proof->status !== ParcelReturnStatus::Completed
                || $proof->seller_received_at === null || (int) $proof->received_by_seller_id !== (int) $parcel->seller_id
                || $proof->stock_condition !== 'resellable' || config('returns.restore_verified_resellable') !== true) {
                throw new \InvalidArgumentException('Stock is held until verified resellable receipt and an approved restoration policy.');
            }
            if ($proof->stock_restored_at !== null) {
                return;
            }
            if (! $this->restoreStock($parcel, 'return')) {
                throw new \InvalidArgumentException('Inventory requires ledger or missing-variant reconciliation before stock release.');
            }
            $proof->forceFill(['stock_restored_at' => now()])->save();
        });
    }

    private function restoreStock(SellerOrder $sellerOrder, string $reason = 'return'): bool
    {
        $sellerOrder->loadMissing('orderItems');
        if ($reason === 'return' && ($sellerOrder->orderItems->isEmpty() || InventoryTransaction::query()->where('seller_order_id', $sellerOrder->id)->where('reason', 'return')->exists())) {
            return false;
        }
        $variants = ProductVariant::query()->whereIn('id', $sellerOrder->orderItems->pluck('variant_id')->filter()->unique())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($reason === 'return' && $sellerOrder->orderItems->contains(fn ($item) => ! $variants->has($item->variant_id))) {
            return false;
        }

        foreach ($sellerOrder->orderItems as $item) {
            $variant = $variants->get($item->variant_id);

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
                'reason' => $reason,
            ]);
        }

        Product::syncRollupsForProducts(
            $sellerOrder->orderItems->pluck('product_id')->unique()->values(),
        );

        return true;
    }

    /**
     * Void a still-pending payment when the seller cancels their slice (COD
     * will never be collected for this seller_order).
     */
    private function cancelPendingPayment(SellerOrder $sellerOrder): void
    {
        $sellerOrder->loadMissing('payment');

        if ($sellerOrder->payment
            && $sellerOrder->payment->status === PaymentStatus::Pending
        ) {
            $sellerOrder->payment->forceFill([
                'status' => PaymentStatus::Cancelled,
            ])->save();
        }
    }

    /**
     * Re-derive the parent order's status from ALL its seller_orders.
     *
     * Called INSIDE the transition's DB transaction. The parent orders row
     * is locked with lockForUpdate and every sibling seller_order is
     * re-queried fresh from the DB, so the rollup always reflects the true
     * current state of the whole order — not a stale in-memory snapshot.
     *
     * Pipeline rank uses OrderStatus::cases() declaration order. Cancelled
     * is terminal and excluded from the active min-rank in Order::computeRollupStatus().
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
