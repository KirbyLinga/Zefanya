<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * product_store_category - Join table: which seller shelf each product sits on.
 *
 * Belongs to:
 *   - products (product_id)
 *   - store_categories (store_category_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('product_store_category')) {
            return;
        }

        Schema::create('product_store_category', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('store_category_id')->constrained('store_categories')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['product_id', 'store_category_id']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('product_store_category');
    }
};
