<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * parcel_loss_cases - A lost parcel and who is liable.
 *
 * Belongs to:
 *   - logistics_providers (logistics_provider_id) - optional
 *   - seller_orders (seller_order_id) - one-to-one
 * Used by: parcel_loss_adjustments
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('parcel_loss_cases')) {
            return;
        }

        Schema::create('parcel_loss_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->unsignedBigInteger('parcel_return_id')->nullable();
            $table->unsignedBigInteger('confirmed_by_admin_id');
            $table->timestamp('confirmed_at');
            $table->string('reason', 1000);
            $table->foreignId('logistics_provider_id')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->string('liability_basis', 30);
            $table->unsignedBigInteger('custody_hub_id')->nullable();
            $table->unsignedBigInteger('merchandise_minor');
            $table->string('compensation_status', 20)->default('pending');
            $table->string('compensation_reference', 100)->nullable();
            $table->date('compensation_paid_on')->nullable();
            $table->timestamp('compensation_paid_at')->nullable();
            $table->unsignedBigInteger('compensation_paid_by_admin_id')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->unsignedBigInteger('recovered_hub_id')->nullable();
            $table->unsignedBigInteger('recovered_by_provider_id')->nullable();
            $table->string('disposition', 30)->nullable();
            $table->string('disposition_note', 1000)->nullable();
            $table->unsignedBigInteger('disposition_by_admin_id')->nullable();
            $table->timestamp('disposition_at')->nullable();
            $table->timestamps();

            $table->unique('seller_order_id');
            $table->index(['logistics_provider_id', 'compensation_status'], 'plc_provider_status_idx');
            $table->index(['seller_id', 'compensation_status'], 'plc_seller_status_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('parcel_loss_cases');
    }
};
