<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only stock ledger (project rule 7): every READ → VALIDATE →
        // MODIFY STOCK operation writes one row inside the same transaction
        // that moved the stock.
        Schema::create('inventory_transactions', function (Blueprint $table): void {
            $table->id();

            // product_id → products.id: RESTRICT — the ledger is history; it
            // must never be orphaned or silently destroyed with the product.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // seller_order_id → seller_orders.id: RESTRICT — permanent link to
            // the order that consumed the stock. Nullable so non-order
            // adjustments (restocks, corrections) can also be recorded later.
            $table->foreignId('seller_order_id')->nullable()->constrained('seller_orders')->restrictOnDelete();

            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('quantity_after');
            // Signed: negative for consumption, positive for restocks.
            $table->integer('delta');

            // Why the stock moved, e.g. 'checkout' (later: 'restock', 'cancel').
            $table->string('reason', 30);

            $table->timestamps();

            // Stock-history reads for a product, newest first.
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
