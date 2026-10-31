<?php

namespace App\Services\Buyer;

use App\Enums\OrderStatus;
use App\Models\Shared\OrderItem;

/** A buyer may purchase at most 20 units of one product, including earlier orders. */
final class BuyerPurchaseLimit
{
    public const MAX = 20;

    public const MESSAGE = 'You can buy at most 20 of this product.';

    public function purchased(int $buyerId, int $productId): int
    {
        return (int) OrderItem::query()
            ->where('product_id', $productId)
            ->whereHas('sellerOrder', function ($query) use ($buyerId): void {
                $query->where('status', '!=', OrderStatus::Cancelled->value)
                    ->whereHas('order', fn ($order) => $order->where('buyer_id', $buyerId));
            })
            ->sum('quantity');
    }

    public function remaining(int $buyerId, int $productId): int
    {
        return max(0, self::MAX - $this->purchased($buyerId, $productId));
    }
}
