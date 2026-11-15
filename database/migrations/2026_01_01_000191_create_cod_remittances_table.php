<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * cod_remittances - Cash a rider collected and the company must pass on.
 *
 * Belongs to:
 *   - logistics_providers (confirmed_by_id) - optional
 *   - couriers (courier_id)
 *   - admins (destination_paid_by_admin_id) - optional
 *   - logistics_providers (logistics_provider_id)
 *   - logistics_remittances (logistics_remittance_id) - optional
 *   - origin_share_payouts (origin_share_payout_id) - optional
 *   - payments (payment_id) - one-to-one
 *   - admins (reconciled_by_admin_id) - optional
 *   - seller_orders (seller_order_id)
 * Used by: seller_settlements
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('cod_remittances')) {
            return;
        }

        Schema::create('cod_remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('courier_id')->constrained('couriers')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('kept_shipping_minor')->nullable();
            $table->unsignedBigInteger('origin_share_minor')->nullable();
            $table->foreignId('origin_share_payout_id')->nullable()->constrained('origin_share_payouts')->restrictOnDelete();
            $table->string('status', 30)->default('PENDING_REMITTANCE');
            $table->timestamps();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('received_amount_minor')->nullable();
            $table->foreignId('logistics_remittance_id')->nullable()->constrained('logistics_remittances')->restrictOnDelete();
            $table->timestamp('zefanya_received_at')->nullable();
            $table->string('reconciliation', 20)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->unsignedBigInteger('destination_share_minor')->nullable();
            $table->string('destination_paid_reference', 100)->nullable();
            $table->date('destination_paid_on')->nullable();
            $table->foreignId('destination_paid_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->unsignedBigInteger('shortage_minor')->default(0);
            $table->string('shortage_reason', 1000)->nullable();
            $table->unsignedBigInteger('shortage_rider_id')->nullable();
            $table->unsignedBigInteger('shortage_recovered_minor')->default(0);
            $table->string('shortage_recovery_reference')->nullable();
            $table->string('shortage_recovery_note', 1000)->nullable();
            $table->timestamp('shortage_recovered_at')->nullable();
            $table->unsignedBigInteger('shortage_recovered_by_id')->nullable();

            $table->unique('payment_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('cod_remittances');
    }
};
