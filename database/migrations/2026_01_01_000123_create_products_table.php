<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * products - Things for sale.
 *
 * Belongs to:
 *   - categories (category_id) - optional
 *   - admins (restricted_by) - optional
 *   - sellers (seller_id)
 * Used by: admin_product_restrictions, cart_items, conversations, inventory_transactions, order_items,
 *   product_approval_events, product_images, product_options, product_revisions,
 *   product_store_category, product_variants, reviews, store_sales, voucher_product, wishlists
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('products')) {
            return;
        }

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('weight_grams')->default(0);
            $table->json('dimensions')->nullable();
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->string('status', 20)->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->char('creation_key', 36)->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('condition', 100)->nullable();
            $table->text('restriction_reason')->nullable();
            $table->timestamp('restricted_at')->nullable();
            $table->foreignId('restricted_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('garment_type', 30)->nullable();

            $table->unique(['seller_id', 'creation_key'], 'product_seller_creation_unique');
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        // Recreated when a pending drop migration is rolled back; it points at this table.
        Schema::dropIfExists('seller_promotion_product');

        Schema::dropIfExists('products');
    }
};
