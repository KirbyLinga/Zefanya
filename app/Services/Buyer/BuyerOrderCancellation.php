<?php

namespace App\Services\Buyer;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Exceptions\StaleStateException;
use App\Models\Buyer\Buyer;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Support\Facades\DB;

/**
 * Buyer cancels one seller_order slice before the seller accepts it.
 *
 * Moved verbatim from Buyer\OrderController::cancelSellerOrder so the web
 * order page and the Buyer mobile API share it. Stock/payment side effects
 * run through SellerOrderStatusService (same path as seller decline).
 * Authorization (OrderPolicy::cancelSellerOrder) stays with the caller.
 *
 * @throws StaleStateException the shipment moved on (already accepted/cancelled)
 * @throws \InvalidArgumentException any other refused transition
 */
class BuyerOrderCancellation
{
    public function cancel(Order $order, SellerOrder $sellerOrder, Buyer $buyer, string $reason): void
    {
        $service = new SellerOrderStatusService;

        DB::transaction(function () use ($sellerOrder, $service, $reason, $buyer, $order): void {
            // Serialize sibling cancellations before taking the shipment lock.
            Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $current = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                abort(404);
            }

            if ($current->status !== OrderStatus::Placed) {
                throw new StaleStateException($current->status === OrderStatus::Cancelled
                    ? 'This shipment is already cancelled.'
                    : 'This order can no longer be cancelled because the seller has already accepted it.');
            }

            $service->transition(
                $current,
                OrderStatus::Cancelled,
                note: 'Buyer cancelled: '.$reason,
                changedBy: 'buyer',
                actor: OrderActor::Buyer,
                actorId: (int) $buyer->id,
                source: 'web',
                expectedFrom: $current->status,
            );
        }, 3);
    }
}
