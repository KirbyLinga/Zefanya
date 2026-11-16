<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * seller_payout_requests - A seller asking to withdraw money.
 *
 * Belongs to:
 *   - admins (decided_by_admin_id) - optional
 *   - sellers (pending_seller_id) - one-to-one - optional
 *   - sellers (seller_id)
 * Used by: seller_payout_allocations
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('seller_payout_requests')) {
            return;
        }

        Schema::create('seller_payout_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->foreignId('pending_seller_id')->nullable()->constrained('sellers')->restrictOnDelete();
            $table->char('idempotency_key', 36);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('debt_deducted_minor')->default(0);
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->string('payout_destination')->nullable();
            $table->string('payout_reference')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->unique(['seller_id', 'idempotency_key'], 'payout_seller_idempotency_unique');
            $table->unique('pending_seller_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('seller_payout_requests');
    }
};
