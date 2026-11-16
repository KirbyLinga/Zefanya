<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * store_categories - A seller's own shelves for organising the shop; a shelf can sit inside another.
 *
 * Belongs to:
 *   - store_categories (parent_id) [itself] - optional
 *   - sellers (seller_id)
 * Used by: product_store_category
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('store_categories')) {
            return;
        }

        Schema::create('store_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('store_categories')->restrictOnDelete();
            $table->string('name', 50);
            $table->string('normalized_name', 50);
            $table->timestamps();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);

            $table->unique(['seller_id', 'normalized_name']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('store_categories');
    }
};
