<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();

            // seller_order_id → seller_orders.id: RESTRICT — permanent record;
            // items belong to their seller_order, never directly to orders.
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();

            // product_id → products.id: SET NULL — keep the line as an audit
            // record even if the product row is later removed (products are
            // soft-deleted in practice, so this rarely fires).
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            // Snapshots at order time: the product may change or vanish later,
            // but the line must always show what was actually bought.
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('price_minor');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
