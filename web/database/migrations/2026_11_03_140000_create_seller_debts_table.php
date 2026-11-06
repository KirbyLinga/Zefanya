<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money a Seller was already paid on an order that was then returned or refunded.
        Schema::create('seller_debts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->foreignId('seller_order_id')->unique()->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('seller_settlement_id')->constrained('seller_settlements')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('settled_minor')->default(0);
            $table->string('reason', 100);
            $table->timestamps();

            $table->index(['seller_id', 'settled_minor']);
        });

        Schema::table('seller_payout_requests', function (Blueprint $table): void {
            // Part of the requested amount that pays down the Seller's debt instead of being sent.
            $table->unsignedBigInteger('debt_deducted_minor')->default(0)->after('amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('seller_payout_requests', function (Blueprint $table): void {
            $table->dropColumn('debt_deducted_minor');
        });
        Schema::dropIfExists('seller_debts');
    }
};
