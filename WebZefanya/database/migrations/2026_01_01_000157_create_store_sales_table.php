<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * store_sales - Sale prices on products or variants for a period.
 *
 * Belongs to:
 *   - products (product_id)
 *   - sellers (seller_id)
 *   - product_variants (variant_id) - optional
 * Used by: order_items
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('store_sales')) {
            return;
        }

        Schema::create('store_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->string('type');
            $table->unsignedBigInteger('value');
            $table->unsignedInteger('flash_quantity')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();

            $table->index(['product_id', 'starts_at', 'ends_at']);
            $table->index(['seller_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('store_sales');
    }
};
