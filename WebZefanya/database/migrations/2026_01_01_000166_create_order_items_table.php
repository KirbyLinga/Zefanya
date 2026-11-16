<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * order_items - The products inside a parcel, with price and variant saved at purchase time.
 *
 * Belongs to:
 *   - products (product_id) - optional
 *   - seller_orders (seller_order_id)
 *   - store_sales (store_sale_id) - optional
 *   - product_variants (variant_id) - optional
 * Used by: reviews
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('order_items')) {
            return;
        }

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('price_minor');
            $table->unsignedInteger('regular_price_minor')->nullable();
            $table->foreignId('store_sale_id')->nullable()->constrained('store_sales')->nullOnDelete();
            $table->timestamps();
            $table->json('variant_options')->nullable();

            $table->index(['product_id', 'seller_order_id'], 'order_items_product_seller_idx');
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('order_items');
    }
};
