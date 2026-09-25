<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();

            // buyer_id → buyers.id: RESTRICT — orders are permanent records;
            // a buyer with order history must never be deleted out from under them.
            $table->foreignId('buyer_id')->constrained('buyers')->restrictOnDelete();

            // Human-facing order number, e.g. ORD-20260922-4F8K2Q.
            $table->string('reference')->unique();

            // Immutable snapshot of the buyer's address at checkout time —
            // a JSON copy, deliberately NOT a live FK, so later address edits
            // can never rewrite historical orders.
            $table->json('shipping_address');

            // Money as unsigned integer centavos (₱1,234.56 = 123456).
            $table->unsignedInteger('subtotal_minor');
            $table->unsignedInteger('discount_minor')->default(0);
            $table->unsignedInteger('total_minor');

            // VARCHAR + App\Enums\OrderStatus cast (MySQL ENUM is deprecated
            // project-wide for business statuses). Starts at PLACED.
            $table->string('status', 20)->default('PLACED');

            $table->timestamps();

            // Buyer order-history listings read "my orders, newest first".
            $table->index(['buyer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
