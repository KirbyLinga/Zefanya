<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rider_payouts - Payments a company made to a rider.
 *
 * Belongs to:
 *   - couriers (courier_id)
 *   - logistics_providers (logistics_provider_id)
 * Used by: rider_earnings
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('rider_payouts')) {
            return;
        }

        Schema::create('rider_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('courier_id')->constrained('couriers')->restrictOnDelete();
            $table->unsignedInteger('earning_count');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 100);
            $table->date('paid_on');
            $table->string('status', 20)->default('PAID');
            $table->timestamps();

            $table->index(['courier_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('rider_payouts');
    }
};
