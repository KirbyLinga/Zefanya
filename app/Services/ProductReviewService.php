<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductReviewService
{
    public function eligibleItem(Product $product, Buyer $buyer): ?OrderItem
    {
        return OrderItem::query()->where('product_id', $product->id)
            ->whereHas('sellerOrder', fn ($query) => $query->where('status', OrderStatus::Completed->value)
                ->whereHas('order', fn ($query) => $query->where('buyer_id', $buyer->id)))
            ->orderBy('id')->first();
    }

    public function save(Product $product, Buyer $buyer, int $rating, ?string $body): Review
    {
        return DB::transaction(function () use ($product, $buyer, $rating, $body): Review {
            // Serialize this buyer's submissions; the unique index also prevents duplicates.
            Buyer::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            $item = $this->eligibleItem($product, $buyer);
            if (! $item) {
                throw ValidationException::withMessages(['review' => 'Confirm receipt of a purchased shipment before reviewing this product.']);
            }

            return Review::query()->updateOrCreate(
                ['buyer_id' => $buyer->id, 'product_id' => $product->id],
                ['order_item_id' => $item->id, 'rating' => $rating, 'body' => $body],
            );
        });
    }
}
