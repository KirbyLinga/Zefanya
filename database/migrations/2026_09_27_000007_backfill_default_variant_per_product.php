<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Strategy (a): every product gets one auto-generated "Default" variant
     * carrying its current price, stock, weight and dimensions.
     *
     * The product-level price/stock columns become maintained rollups
     * (price = min variant price, stock = SUM(variants)) — this backfill makes
     * them trivially correct: 1 variant, same numbers. Legacy readers (buyer
     * home cards, seeded demo rows) keep seeing the right values untouched.
     *
     * Idempotent: products that already have a variant (e.g. re-run after a
     * partial failure) are skipped. Uses CURRENT_TIMESTAMP/1/0 literals (not
     * NOW()/TRUE) so the statement runs on both MySQL and the sqlite test DB.
     */
    public function up(): void
    {
        // One INSERT ... SELECT for all 496 live rows — no N+1 through Eloquent.
        DB::statement(<<<'SQL'
            INSERT INTO product_variants (product_id, sku, price, stock_quantity, is_available, weight_grams, dimensions, created_at, updated_at)
            SELECT
                p.id,
                NULL,
                p.price,
                p.stock_quantity,
                1,
                p.weight_grams,
                p.dimensions,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM products p
            WHERE p.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id)
        SQL);

        // Products deleted earlier still get their Default variant so the
        // schema invariant ("every product has ≥1 variant") holds uniformly;
        // they are soft-deleted rows and never sell again.
        DB::statement(<<<'SQL'
            INSERT INTO product_variants (product_id, sku, price, stock_quantity, is_available, weight_grams, dimensions, created_at, updated_at)
            SELECT
                p.id,
                NULL,
                p.price,
                p.stock_quantity,
                0,
                p.weight_grams,
                p.dimensions,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM products p
            WHERE p.deleted_at IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id)
        SQL);
    }

    public function down(): void
    {
        // Only remove Default variants that were created by this migration:
        // they carry no SKU and no option values. Variants created later by
        // sellers (or wired to cart lines / images) are left alone. Written
        // with IN + NOT EXISTS (no multi-table DELETE) so it also runs on
        // sqlite, where "DELETE t1 FROM t1 JOIN t2" is not supported.
        DB::statement(<<<'SQL'
            DELETE FROM product_variants
            WHERE sku IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM product_variant_option_values pov
                  WHERE pov.product_variant_id = product_variants.id
              )
              AND EXISTS (
                  SELECT 1 FROM products p
                  WHERE p.id = product_variants.product_id
                    AND (
                        (p.price = product_variants.price AND p.stock_quantity = product_variants.stock_quantity)
                        OR p.deleted_at IS NOT NULL
                    )
              )
        SQL);
    }
};
