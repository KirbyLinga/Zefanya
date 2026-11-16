<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * manifests - A batch of parcels moving between two hubs of the same company.
 *
 * Belongs to:
 *   - logistics_hubs (destination_hub_id + logistics_provider_id)
 *   - logistics_providers (logistics_provider_id)
 *   - logistics_hubs (origin_hub_id + logistics_provider_id)
 *   - transfer_couriers (transfer_courier_id) - optional
 * Used by: manifest_items, transfer_courier_assignments
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('manifests')) {
            return;
        }

        Schema::create('manifests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedBigInteger('origin_hub_id');
            $table->unsignedBigInteger('destination_hub_id');
            $table->string('status', 32)->default('DRAFT');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamp('overdue_escalated_at')->nullable();
            $table->string('dispatched_by_type', 20)->nullable();
            $table->unsignedBigInteger('dispatched_by_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('received_by_type', 20)->nullable();
            $table->unsignedBigInteger('received_by_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by_type', 20)->nullable();
            $table->unsignedBigInteger('cancelled_by_id')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->string('direction', 12)->default('forward');
            $table->boolean('transport_required')->default(false);
            $table->foreignId('transfer_courier_id')->nullable()->constrained('transfer_couriers')->restrictOnDelete();
            $table->string('transport_vehicle_type', 20)->nullable();
            $table->string('transport_plate_number', 20)->nullable();
            $table->timestamp('transport_assigned_at')->nullable();
            $table->unsignedBigInteger('transport_assigned_by_id')->nullable();

            $table->index(['destination_hub_id', 'status'], 'manifests_incoming_status_idx');
            $table->index(['logistics_provider_id', 'status'], 'manifests_provider_status_idx');
            $table->index(['origin_hub_id', 'destination_hub_id'], 'manifests_route_idx');
            $table->index(['status', 'dispatched_at'], 'manifests_status_dispatched_idx');
            $table->foreign(['destination_hub_id', 'logistics_provider_id'], 'manifests_destination_provider_fk')->references(['id', 'logistics_provider_id'])->on('logistics_hubs')->restrictOnDelete();
            $table->foreign(['origin_hub_id', 'logistics_provider_id'], 'manifests_origin_provider_fk')->references(['id', 'logistics_provider_id'])->on('logistics_hubs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('manifests');
    }
};
