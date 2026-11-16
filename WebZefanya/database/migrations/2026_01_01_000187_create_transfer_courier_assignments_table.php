<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * transfer_courier_assignments - Which transfer courier carried which manifest or hand-off.
 *
 * Belongs to:
 *   - logistics_providers (logistics_provider_id)
 *   - manifests (manifest_id) - optional
 *   - parcel_handoffs (parcel_handoff_id) - optional
 *   - transfer_couriers (transfer_courier_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('transfer_courier_assignments')) {
            return;
        }

        Schema::create('transfer_courier_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_courier_id')->constrained('transfer_couriers')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('manifest_id')->nullable()->constrained('manifests')->cascadeOnDelete();
            $table->foreignId('parcel_handoff_id')->nullable()->constrained('parcel_handoffs')->restrictOnDelete();
            $table->string('vehicle_type', 20);
            $table->string('plate_number', 20)->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();

            $table->index(['transfer_courier_id', 'assigned_at'], 'tc_assignments_courier_assigned_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('transfer_courier_assignments');
    }
};
