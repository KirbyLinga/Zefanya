<?php

namespace App\Models\Concerns;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Keeps products.price / products.stock_quantity correct as ROLLUPS of the
 * product's variants (strategy (a)):
 *
 *   products.price           = MIN(product_variants.price)   (what a buyer pays from)
 *   products.stock_quantity  = SUM(product_variants.stock_quantity)
 *
 * Legacy readers (buyer home cards, catalog helpers, the seeded demo rows)
 * keep working untouched; the readers that need per-variant precision are
 * re-pointed at variants explicitly.
 */
trait SyncsVariantRollups
{
    public function syncVariantRollups(): void
    {
        // Deliberately DB::table, not the Eloquent builder: swapping FROM on a
        // Product query would drag the SoftDeletes scope (products.deleted_at)
        // into a query that no longer selects from products.
        $rollups = DB::table('product_variants')
            ->where('product_id', $this->getKey())
            ->selectRaw('MIN(price) as min_price, COALESCE(SUM(stock_quantity), 0) as total_stock')
            ->first();

        // A product always has ≥1 variant (backfill migration + observer),
        // but guard anyway: fall back to the current values, never null.
        DB::table('products')
            ->where('id', $this->getKey())
            ->update([
                'price' => $rollups?->min_price ?? $this->price,
                'stock_quantity' => (int) ($rollups?->total_stock ?? $this->stock_quantity),
            ]);
    }

    /**
     * Sync rollups for every product touched by the given variant rows — used
     * by bulk operations (checkout decrements several variants at once).
     *
     * @param  iterable<int>  $productIds
     */
    public static function syncRollupsForProducts(iterable $productIds): void
    {
        $ids = collect($productIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        // Alias-free and portable: sqlite (the test DB) rejects "UPDATE
        // products p SET ..." aliases entirely, so every reference is the
        // plain table name. Ids are int-cast before interpolation (a NOWDOC
        // would NOT interpolate, so this must be a plain heredoc).
        $idList = $ids->map(fn ($id): int => (int) $id)->implode(', ');

        DB::statement(<<<SQL
            UPDATE products
            SET price = (
                    SELECT MIN(price) FROM product_variants WHERE product_variants.product_id = products.id
                ),
                stock_quantity = (
                    SELECT COALESCE(SUM(stock_quantity), 0) FROM product_variants WHERE product_variants.product_id = products.id
                )
            WHERE products.id IN ($idList)
        SQL);
    }
}
