<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * product_variants - One buyable combination of option values, with its own stock and price.
 *
 * Belongs to:
 *   - products (product_id)
 * Used by: cart_items, inventory_transactions, order_items, product_images, product_variant_option_values,
 *   store_sales
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('product_variants')) {
            return;
        }

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 64)->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('weight_grams')->nullable();
            $table->json('dimensions')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'is_available', 'stock_quantity'], 'product_variants_product_avail_stock_idx');
            $table->index(['product_id', 'is_available']);
            $table->index('sku');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('product_variants');
    }
};
