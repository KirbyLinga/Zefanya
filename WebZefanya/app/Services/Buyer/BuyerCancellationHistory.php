<?php

namespace App\Services\Buyer;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Shared\StatusHistory;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Builder;

/** Factual history only. Initiating a cancellation is not a finding of fault. */
class BuyerCancellationHistory
{
    public function query(int $buyerId): Builder
    {
        $query = StatusHistory::query()->where('to_status', OrderStatus::Cancelled)
            ->whereHas('sellerOrder.order', fn (Builder $q) => $q->where('buyer_id', $buyerId));
        if (! RuntimeSchema::hasColumn('status_histories', 'actor_type')) {
            return $query->whereRaw('1 = 0'); // Never infer the actor from a free-text reason.
        }

        return $query->where('actor_type', OrderActor::Buyer->value)->where('actor_id', $buyerId);
    }

    public function orderCount(int $buyerId): int
    {
        // A multi-seller checkout cancelled in several slices is one order.
        return (int) $this->query($buyerId)->join('seller_orders', 'seller_orders.id', '=', 'status_histories.seller_order_id')
            ->distinct()->count('seller_orders.order_id');
    }

    public function notice(int $buyerId): ?string
    {
        $count = $this->orderCount($buyerId);
        if ($count === 0) {
            return null;
        }

        // No misconduct threshold is defined. Show a neutral history reminder,
        // including valid cancellations, rather than assigning fault or a strike.
        return 'Your recorded history includes cancellation requests for '.$count.' '.($count === 1 ? 'order' : 'orders').'. Repeated cancellations may be reviewed by the platform. Review considers the reasons and circumstances, including delays or issues outside your control. You can still cancel eligible orders; legitimate cancellation rights are unchanged.';
    }
}
