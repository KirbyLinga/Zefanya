<?php

namespace App\Models\Concerns;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;

/**
 * Guarantees the "every product has ≥1 variant" invariant for products created
 * AFTER the backfill migration: a brand-new product (no variant rows yet) gets
 * its auto Default variant immediately.
 */
trait BackfillsDefaultVariant
{
    public static function bootBackfillsDefaultVariant(): void
    {
        static::created(function ($product): void {
            if ($product instanceof Product && ! $product->variants()->exists()) {
                ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'sku' => null,
                    'price' => $product->price,
                    'stock_quantity' => (int) $product->stock_quantity,
                    'is_available' => $product->status === 'active',
                    'weight_grams' => $product->weight_grams,
                    'dimensions' => $product->dimensions,
                ]);
            }
        });
    }
}
