<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * transfer_couriers - Drivers who carry parcels between hubs (not delivery riders).
 *
 * Belongs to:
 *   - logistics_providers (logistics_provider_id)
 * Used by: courier_accounts, manifests, parcel_handoffs, transfer_courier_assignments
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('transfer_couriers')) {
            return;
        }

        Schema::create('transfer_couriers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('contact_no', 20);
            $table->string('vehicle_type', 20);
            $table->string('plate_number', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['logistics_provider_id', 'status']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('transfer_couriers');
    }
};
