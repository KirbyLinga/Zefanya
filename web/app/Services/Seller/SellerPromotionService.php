<?php

namespace App\Services\Seller;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPromotion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SellerPromotionService
{
    /** @param array<string, mixed> $attributes */
    public function create(Seller $seller, array $attributes): SellerPromotion
    {
        return DB::transaction(function () use ($seller, $attributes): SellerPromotion {
            $productIds = collect($attributes['product_ids'])->unique()->values();
            $owned = Product::query()
                ->where('seller_id', $seller->id)
                ->whereIn('id', $productIds)
                ->count();

            if ($owned !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'product_ids' => 'Choose products from your store.',
                ]);
            }

            $overlap = SellerPromotion::query()
                ->where('seller_id', $seller->id)
                ->where('kind', $attributes['kind'])
                ->where('starts_at', '<', $attributes['ends_at'])
                ->where('ends_at', '>', $attributes['starts_at'])
                ->whereHas('products', fn ($query) => $query->whereIn('products.id', $productIds))
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'product_ids' => 'This product already has an overlapping deal of the same kind.',
                ]);
            }

            $promotion = SellerPromotion::query()->create([
                'seller_id' => $seller->id,
                'kind' => $attributes['kind'],
                'name' => $attributes['name'],
                'percent' => $attributes['percent'],
                'buy_quantity' => $attributes['buy_quantity'],
                'get_quantity' => $attributes['get_quantity'],
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'],
            ]);
            $promotion->products()->sync($productIds->all());

            return $promotion;
        });
    }
}
