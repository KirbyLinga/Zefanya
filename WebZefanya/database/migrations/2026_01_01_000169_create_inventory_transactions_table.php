<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * inventory_transactions - Every increase or decrease of stock.
 *
 * Belongs to:
 *   - products (product_id)
 *   - seller_orders (seller_order_id) - optional
 *   - product_variants (variant_id) - optional
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('inventory_transactions')) {
            return;
        }

        Schema::create('inventory_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('seller_order_id')->nullable()->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('quantity_after');
            $table->integer('delta');
            $table->string('reason', 30);
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('inventory_transactions');
    }
};
