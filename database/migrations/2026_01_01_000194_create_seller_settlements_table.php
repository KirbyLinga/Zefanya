<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * seller_settlements - A seller's money for one delivered parcel.
 *
 * Belongs to:
 *   - cod_remittances (cod_remittance_id) - one-to-one
 *   - admins (paid_by_admin_id) - optional
 *   - payments (payment_id)
 *   - sellers (seller_id)
 *   - seller_orders (seller_order_id) - one-to-one
 * Used by: seller_debts, seller_payout_allocations
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('seller_settlements')) {
            return;
        }

        Schema::create('seller_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('cod_remittance_id')->constrained('cod_remittances')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->unsignedBigInteger('collected_amount_minor');
            $table->unsignedBigInteger('shipping_allocation_minor');
            $table->unsignedSmallInteger('commission_bps')->nullable();
            $table->unsignedBigInteger('commission_minor')->nullable();
            $table->bigInteger('seller_payout_minor')->nullable();
            $table->timestamp('financials_finalized_at')->nullable();
            $table->string('pending_reason')->nullable();
            $table->string('status', 30)->default('PENDING_PAYOUT');
            $table->unsignedBigInteger('paid_amount_minor')->nullable();
            $table->string('payout_reference')->nullable();
            $table->foreignId('paid_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('seller_funded_discount_minor')->nullable();
            $table->unsignedBigInteger('marketplace_funded_discount_minor')->nullable();

            $table->unique('cod_remittance_id');
            $table->unique('seller_order_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('seller_settlements');
    }
};
