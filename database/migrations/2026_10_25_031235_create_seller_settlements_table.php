<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->unique()->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('cod_remittance_id')->unique()->constrained('cod_remittances')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->unsignedBigInteger('collected_amount_minor');
            $table->unsignedBigInteger('shipping_allocation_minor');
            // Unknown until an authoritative commission finalizer exists.
            $table->unsignedSmallInteger('commission_bps')->nullable();
            $table->unsignedBigInteger('commission_minor')->nullable();
            $table->unsignedBigInteger('seller_payout_minor')->nullable();
            $table->timestamp('financials_finalized_at')->nullable();
            $table->string('pending_reason')->nullable();
            $table->string('status', 30)->default('PENDING_PAYOUT');
            $table->unsignedBigInteger('paid_amount_minor')->nullable();
            $table->string('payout_reference', 255)->nullable();
            $table->foreignId('paid_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_settlements');
    }
};
