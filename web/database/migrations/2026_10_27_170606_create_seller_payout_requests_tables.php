<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('seller_payout_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            // NULL after cancellation; a unique Seller ID enforces one pending request.
            $table->foreignId('pending_seller_id')->nullable()->unique()->constrained('sellers')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['seller_id', 'idempotency_key'], 'payout_seller_idempotency_unique');
        });

        Schema::create('seller_payout_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_payout_request_id')->constrained('seller_payout_requests')->restrictOnDelete();
            $table->foreignId('seller_settlement_id')->constrained('seller_settlements')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->unique(['seller_payout_request_id', 'seller_settlement_id'], 'payout_settlement_allocation_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_payout_allocations');
        Schema::dropIfExists('seller_payout_requests');
    }
};
