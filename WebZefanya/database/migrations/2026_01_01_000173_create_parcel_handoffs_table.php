<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * parcel_handoffs - A parcel passed from one delivery company to a partner company.
 *
 * Belongs to:
 *   - logistics_provider_links (logistics_provider_link_id + origin_provider_id + destination_provider_id) - optional
 *   - logistics_hubs (receiving_hub_id + destination_provider_id)
 *   - logistics_hubs (sending_hub_id + origin_provider_id)
 *   - logistics_providers (destination_provider_id)
 *   - logistics_providers (origin_provider_id)
 *   - seller_orders (seller_order_id)
 *   - transfer_couriers (transfer_courier_id) - optional
 * Used by: transfer_courier_assignments
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('parcel_handoffs')) {
            return;
        }

        Schema::create('parcel_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->unsignedBigInteger('logistics_provider_link_id')->nullable();
            $table->foreignId('origin_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('destination_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedBigInteger('sending_hub_id');
            $table->unsignedBigInteger('receiving_hub_id');
            $table->string('status', 20)->default('REQUESTED');
            $table->timestamp('requested_at')->nullable();
            $table->string('requested_by_type', 20)->nullable();
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_by_type', 20)->nullable();
            $table->unsignedBigInteger('accepted_by_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejected_by_type', 20)->nullable();
            $table->unsignedBigInteger('rejected_by_id')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->string('dispatched_by_type', 20)->nullable();
            $table->unsignedBigInteger('dispatched_by_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('received_by_type', 20)->nullable();
            $table->unsignedBigInteger('received_by_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_by_type', 20)->nullable();
            $table->unsignedBigInteger('cancelled_by_id')->nullable();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->string('recovered_by_type', 20)->nullable();
            $table->unsignedBigInteger('recovered_by_id')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamp('overdue_escalated_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('active_seller_order_id')->nullable()->storedAs('case when status in (\'REQUESTED\', \'ACCEPTED\', \'IN_TRANSIT\') then seller_order_id else null end');
            $table->string('direction', 12)->default('forward');
            $table->boolean('transport_required')->default(false);
            $table->foreignId('transfer_courier_id')->nullable()->constrained('transfer_couriers')->restrictOnDelete();
            $table->string('transport_vehicle_type', 20)->nullable();
            $table->string('transport_plate_number', 20)->nullable();
            $table->timestamp('transport_assigned_at')->nullable();
            $table->unsignedBigInteger('transport_assigned_by_id')->nullable();

            $table->index(['destination_provider_id', 'status'], 'handoffs_incoming_idx');
            $table->index(['origin_provider_id', 'status'], 'handoffs_outgoing_idx');
            $table->index(['seller_order_id', 'created_at'], 'handoffs_parcel_history_idx');
            $table->unique('active_seller_order_id', 'parcel_handoffs_active_parcel_unique');
            $table->foreign(['logistics_provider_link_id', 'origin_provider_id', 'destination_provider_id'], 'handoffs_link_route_fk')->references(['id', 'origin_provider_id', 'destination_provider_id'])->on('logistics_provider_links')->restrictOnDelete();
            $table->foreign(['receiving_hub_id', 'destination_provider_id'], 'handoffs_receiving_provider_fk')->references(['id', 'logistics_provider_id'])->on('logistics_hubs')->restrictOnDelete();
            $table->foreign(['sending_hub_id', 'origin_provider_id'], 'handoffs_sending_provider_fk')->references(['id', 'logistics_provider_id'])->on('logistics_hubs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('parcel_handoffs');
    }
};
