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
 * Buyer cancels one seller_order slice before Ready for Pickup.
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
    public const REASONS = [
        'Changed my mind',
        'Ordered the wrong item',
        'Need to update the address',
        'Found a better price',
        'Other',
    ];

    public function cancel(Order $order, SellerOrder $sellerOrder, Buyer $buyer, string $reason): void
    {
        $service = new SellerOrderStatusService;

        DB::transaction(function () use ($sellerOrder, $service, $reason, $buyer, $order): void {
            // Serialize sibling cancellations before taking the shipment lock.
            $parent = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $parent->buyer_id === (int) $buyer->id, 404);
            $current = SellerOrder::query()
                ->whereKey($sellerOrder->id)
                ->lockForUpdate()
                ->first();

            if ($current === null || (int) $current->order_id !== (int) $parent->id) {
                abort(404);
            }

            if (! $current->status->canBuyerCancel()) {
                throw new StaleStateException($current->status === OrderStatus::Cancelled
                    ? 'This shipment is already cancelled.'
                    : 'This order can no longer be cancelled because it is ready for pickup or has moved on.');
            }

            $reason = trim($reason);
            \Illuminate\Support\Facades\Validator::make(['reason' => $reason], [
                'reason' => ['required', \Illuminate\Validation\Rule::in(self::REASONS)],
            ])->validate();

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
