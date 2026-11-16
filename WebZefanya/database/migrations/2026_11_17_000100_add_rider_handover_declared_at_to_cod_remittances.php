<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rider-side handover signal for COD cash.
 *
 * Additive and nullable: the rider marks the cash as handed over; Logistics still confirms the
 * received amount, which remains the only step that changes the remittance status or any money.
 * Existing rows stay NULL ("not yet marked").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cod_remittances') || Schema::hasColumn('cod_remittances', 'rider_handover_declared_at')) {
            return;
        }

        Schema::table('cod_remittances', function (Blueprint $table): void {
            $table->timestamp('rider_handover_declared_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('cod_remittances') && Schema::hasColumn('cod_remittances', 'rider_handover_declared_at')) {
            Schema::table('cod_remittances', function (Blueprint $table): void {
                $table->dropColumn('rider_handover_declared_at');
            });
        }
    }
};
