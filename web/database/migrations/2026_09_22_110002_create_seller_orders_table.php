<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_orders', function (Blueprint $table): void {
            $table->id();

            // order_id → orders.id: RESTRICT — permanent record; a seller_order
            // is part of the buyer's transaction history.
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();

            // seller_id → sellers.id: RESTRICT — permanent record; a seller with
            // transaction history must not be deletable.
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();

            // This shop's slice of the order, in unsigned integer centavos
            // (before the order-level voucher discount, which lives on orders).
            $table->unsignedInteger('subtotal_minor');

            // VARCHAR + App\Enums\OrderStatus cast. Starts at PLACED; the seller
            // fulfilment workflow (CONFIRMED → … → READY_FOR_PICKUP) is Phase 3.
            $table->string('status', 20)->default('PLACED');

            $table->timestamps();

            // Exactly one seller_order per shop per order.
            $table->unique(['order_id', 'seller_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_orders');
    }
};
