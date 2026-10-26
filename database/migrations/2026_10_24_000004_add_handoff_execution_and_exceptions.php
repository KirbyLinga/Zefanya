<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.3–5.5 execution support.
 *
 * custody_provider_id / custody_hub_id are separate from carrier_provider_id:
 * the carrier (origin provider selected at checkout) keeps commercial ownership
 * while physical custody moves between providers through verified handoffs.
 *
 * handoff_destination_provider_id records the cross-province partner resolved
 * at checkout so the route can be revalidated without changing ownership.
 *
 * parcel_handoff_exceptions makes an unserviceable handoff visible to Admin
 * instead of silently dropping the parcel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('handoff_destination_provider_id')->nullable()->after('carrier_provider_id');
            $table->unsignedBigInteger('custody_provider_id')->nullable()->after('handoff_destination_provider_id');
            $table->unsignedBigInteger('custody_hub_id')->nullable()->after('custody_provider_id');

            $table->foreign('handoff_destination_provider_id', 'seller_orders_handoff_destination_fk')
                ->references('id')->on('logistics_providers')->restrictOnDelete();
            $table->foreign('custody_provider_id', 'seller_orders_custody_provider_fk')
                ->references('id')->on('logistics_providers')->restrictOnDelete();
            $table->foreign('custody_hub_id', 'seller_orders_custody_hub_fk')
                ->references('id')->on('logistics_hubs')->restrictOnDelete();
            $table->index(['custody_provider_id', 'status'], 'seller_orders_custody_status_idx');
        });

        Schema::create('parcel_handoff_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('origin_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('status', 20)->default('OPEN');
            $table->string('destination_barangay_code', 20)->nullable();
            $table->text('reason');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->foreign('resolved_by')->references('id')->on('admins')->nullOnDelete();
            $table->index(['status', 'created_at'], 'handoff_exceptions_status_idx');
            $table->index(['origin_provider_id', 'status'], 'handoff_exceptions_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_handoff_exceptions');

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropForeign('seller_orders_handoff_destination_fk');
            $table->dropForeign('seller_orders_custody_provider_fk');
            $table->dropForeign('seller_orders_custody_hub_fk');
            $table->dropIndex('seller_orders_custody_status_idx');
            $table->dropColumn([
                'handoff_destination_provider_id',
                'custody_provider_id',
                'custody_hub_id',
            ]);
        });
    }
};
