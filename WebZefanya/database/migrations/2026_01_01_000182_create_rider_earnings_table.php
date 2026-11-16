<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rider_earnings - What a rider earned for a pickup or delivery.
 *
 * Belongs to:
 *   - couriers (courier_id)
 *   - logistics_providers (logistics_provider_id)
 *   - pickup_sessions (pickup_session_id) - optional
 *   - rider_payouts (rider_payout_id) - optional
 *   - seller_orders (seller_order_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('rider_earnings')) {
            return;
        }

        Schema::create('rider_earnings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('courier_id')->constrained('couriers')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('job', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('earned_at');
            $table->foreignId('rider_payout_id')->nullable()->constrained('rider_payouts')->restrictOnDelete();
            $table->timestamps();
            $table->foreignId('pickup_session_id')->nullable()->constrained('pickup_sessions')->restrictOnDelete();
            $table->unsignedTinyInteger('session_first')->nullable();

            $table->index(['courier_id', 'earned_at']);
            $table->index(['logistics_provider_id', 'earned_at']);
            $table->unique(['pickup_session_id', 'session_first']);
            $table->unique(['seller_order_id', 'job']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('rider_earnings');
    }
};
