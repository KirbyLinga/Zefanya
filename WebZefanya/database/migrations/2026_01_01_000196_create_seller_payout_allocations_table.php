<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * seller_payout_allocations - Join table: which settlements a payout request pays.
 *
 * Belongs to:
 *   - seller_payout_requests (seller_payout_request_id)
 *   - seller_settlements (seller_settlement_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('seller_payout_allocations')) {
            return;
        }

        Schema::create('seller_payout_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_payout_request_id')->constrained('seller_payout_requests')->restrictOnDelete();
            $table->foreignId('seller_settlement_id')->constrained('seller_settlements')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');

            $table->unique(['seller_payout_request_id', 'seller_settlement_id'], 'payout_settlement_allocation_unique');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('seller_payout_allocations');
    }
};
