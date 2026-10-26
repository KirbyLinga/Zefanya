<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-provider custody transfers for a single seller_order parcel.
 *
 * Each row is an append-only audit record. Only one handoff per parcel may be
 * active at a time (REQUESTED/ACCEPTED/IN_TRANSIT); terminal rows (REJECTED/
 * RECEIVED) stay in place and free the parcel for a later handoff. The original
 * seller_orders.tracking_number remains the single tracking identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->unsignedBigInteger('logistics_provider_link_id');
            $table->foreignId('origin_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('destination_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedBigInteger('sending_hub_id');
            $table->unsignedBigInteger('receiving_hub_id');

            // App\Enums\ParcelHandoffStatus values (VARCHAR, mirrors the enum).
            $table->string('status', 20)->default('REQUESTED');
            // Actor conventions mirror manifests; no new tracking identity is issued.
            foreach (['requested', 'accepted', 'rejected', 'dispatched', 'received'] as $event) {
                $table->timestamp($event.'_at')->nullable();
                $table->string($event.'_by_type', 20)->nullable();
                $table->unsignedBigInteger($event.'_by_id')->nullable();
            }
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('active_seller_order_id')->nullable()
                ->storedAs("case when status in ('REQUESTED', 'ACCEPTED', 'IN_TRANSIT') then seller_order_id else null end");
            $table->unique('active_seller_order_id', 'parcel_handoffs_active_parcel_unique');
            $table->foreign(['logistics_provider_link_id', 'origin_provider_id', 'destination_provider_id'], 'handoffs_link_route_fk')
                ->references(['id', 'origin_provider_id', 'destination_provider_id'])->on('logistics_provider_links')->restrictOnDelete();
            $table->foreign(['sending_hub_id', 'origin_provider_id'], 'handoffs_sending_provider_fk')
                ->references(['id', 'logistics_provider_id'])->on('logistics_hubs')->restrictOnDelete();
            $table->foreign(['receiving_hub_id', 'destination_provider_id'], 'handoffs_receiving_provider_fk')
                ->references(['id', 'logistics_provider_id'])->on('logistics_hubs')->restrictOnDelete();
            $table->index(['seller_order_id', 'created_at'], 'handoffs_parcel_history_idx');
            $table->index(['origin_provider_id', 'status'], 'handoffs_outgoing_idx');
            $table->index(['destination_provider_id', 'status'], 'handoffs_incoming_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_handoffs');
    }
};
