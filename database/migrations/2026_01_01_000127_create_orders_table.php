<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * orders - One checkout by a buyer.
 *
 * Belongs to:
 *   - buyers (buyer_id)
 * Used by: conversations, seller_orders
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('orders')) {
            return;
        }

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->restrictOnDelete();
            $table->string('reference');
            $table->json('shipping_address');
            $table->unsignedInteger('subtotal_minor');
            $table->unsignedInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('shop_discount_minor')->default(0);
            $table->unsignedBigInteger('platform_discount_minor')->default(0);
            $table->unsignedBigInteger('shipping_discount_minor')->default(0);
            $table->unsignedInteger('total_minor');
            $table->string('status', 20)->default('PLACED');
            $table->timestamps();

            $table->index(['buyer_id', 'created_at']);
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('orders');
    }
};
