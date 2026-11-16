<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * origin_share_payouts - Zefanya paying the origin company its 40% share.
 *
 * Belongs to:
 *   - logistics_providers (logistics_provider_id)
 *   - admins (paid_by_admin_id)
 * Used by: cod_remittances
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('origin_share_payouts')) {
            return;
        }

        Schema::create('origin_share_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedInteger('parcel_count');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 100);
            $table->date('paid_on');
            $table->string('status', 20)->default('PAID');
            $table->foreignId('paid_by_admin_id')->constrained('admins')->restrictOnDelete();
            $table->timestamps();

            $table->index(['logistics_provider_id', 'reference'], 'origin_payout_reference_lookup');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('origin_share_payouts');
    }
};
