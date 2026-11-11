<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COD shortage accountability (audit I-05).
 *
 * When a Logistics company receives less cash than the rider collected, it records
 * the actual cash received, the shortage, the responsible Rider and the reason.
 * The missing money is never marked as received and no Seller settlement is
 * prepared until it is recovered. A later manual recovery increases the received
 * cash and closes the outstanding shortage.
 *
 * Additive only; nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->unsignedBigInteger('shortage_minor')->default(0);
            $table->string('shortage_reason', 1000)->nullable();
            $table->unsignedBigInteger('shortage_rider_id')->nullable();
            $table->unsignedBigInteger('shortage_recovered_minor')->default(0);
            $table->string('shortage_recovery_reference', 255)->nullable();
            $table->string('shortage_recovery_note', 1000)->nullable();
            $table->timestamp('shortage_recovered_at')->nullable();
            $table->unsignedBigInteger('shortage_recovered_by_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->dropColumn([
                'shortage_minor', 'shortage_reason', 'shortage_rider_id', 'shortage_recovered_minor',
                'shortage_recovery_reference', 'shortage_recovery_note', 'shortage_recovered_at',
                'shortage_recovered_by_id',
            ]);
        });
    }
};
