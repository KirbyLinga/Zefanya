<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Focused indexes for Buyer product listing queries (Stage 1). */
    public function up(): void
    {
        // Supports the `sold_units` subquery: order_items by product, then the
        // seller_orders lookup filtered by seller_id + fulfilled status.
        Schema::table('order_items', function (Blueprint $table): void {
            $table->index(['product_id', 'seller_order_id'], 'order_items_product_seller_idx');
        });

        // Supports the `sold_units` subquery's seller_orders filter:
        // seller_id + status IN (Delivered, Completed).
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->index(['seller_id', 'status'], 'seller_orders_seller_status_idx');
        });

        // Covering index for variant aggregates (catalog_stock_quantity,
        // variants_count, purchasableVariants existence) so MySQL never reads
        // the product_variants heap for the common available/in-stock case.
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->index(
                ['product_id', 'is_available', 'stock_quantity'],
                'product_variants_product_avail_stock_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_product_seller_idx');
        });
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropIndex('seller_orders_seller_status_idx');
        });
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex('product_variants_product_avail_stock_idx');
        });
    }
};
