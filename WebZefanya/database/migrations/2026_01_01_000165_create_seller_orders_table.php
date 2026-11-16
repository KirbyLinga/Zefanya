<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * seller_orders - One parcel: the part of an order that comes from one seller. The hub of the whole database.
 *
 * Belongs to:
 *   - logistics_providers (carrier_provider_id) - optional
 *   - couriers (courier_id) - optional
 *   - logistics_hubs (custody_hub_id) - optional
 *   - logistics_providers (custody_provider_id) - optional
 *   - couriers (delivery_courier_id) - optional
 *   - areas (destination_area_id) - optional
 *   - logistics_providers (handoff_destination_provider_id) - optional
 *   - orders (order_id)
 *   - logistics_hubs (origin_station_hub_id) - optional
 *   - pickup_sessions (pickup_session_id) - optional
 *   - vouchers (platform_voucher_id) - optional
 *   - sellers (seller_id)
 *   - vouchers (shipping_voucher_id) - optional
 *   - vouchers (shop_voucher_id) - optional
 *   - vouchers (voucher_id) - optional
 * Used by: cod_delivery_strikes, cod_remittances, delivery_assignments, delivery_proofs, incidents,
 *   inventory_exceptions, inventory_transactions, logistics_return_charges, manifest_items,
 *   order_items, parcel_handoff_exceptions, parcel_handoffs, parcel_loss_cases,
 *   parcel_provider_allocations, parcel_returns, parcel_route_rereoutes, parcel_routes, payments,
 *   pickup_attempts, platform_refund_expenses, rider_earnings, seller_debts, seller_settlements,
 *   status_histories
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('seller_orders')) {
            return;
        }

        Schema::create('seller_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->unsignedInteger('subtotal_minor');
            $table->unsignedInteger('parcel_weight_grams')->nullable();
            $table->string('status', 20)->default('PLACED');
            $table->timestamp('preparation_due_at')->nullable();
            $table->timestamp('preparation_overdue_notified_at')->nullable();
            $table->string('tracking_number', 64)->nullable();
            $table->foreignId('courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->foreignId('delivery_courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->string('delivery_failure_reason')->nullable();
            $table->boolean('needs_reassignment')->default(false);
            $table->text('reassignment_reason')->nullable();
            $table->timestamp('reassignment_flagged_at')->nullable();
            $table->timestamp('handover_at')->nullable();
            $table->foreignId('destination_area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->foreignId('origin_station_hub_id')->nullable()->constrained('logistics_hubs')->nullOnDelete();
            $table->text('delivery_failure_notes')->nullable();
            $table->unsignedTinyInteger('delivery_attempts')->default(0);
            $table->timestamp('returned_to_station_at')->nullable();
            $table->timestamps();
            $table->json('delivery_address_verification')->nullable();
            $table->foreignId('carrier_provider_id')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedBigInteger('handoff_destination_provider_id')->nullable();
            $table->unsignedBigInteger('custody_provider_id')->nullable();
            $table->unsignedBigInteger('custody_hub_id')->nullable();
            $table->unsignedInteger('shipping_fee_minor')->default(0);
            $table->json('pickup_address_snapshot')->nullable();
            $table->unsignedSmallInteger('commission_bps')->nullable();
            $table->unsignedBigInteger('commission_minor')->nullable();
            $table->bigInteger('seller_net_minor')->nullable();
            $table->timestamp('commission_finalized_at')->nullable();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->restrictOnDelete();
            $table->string('voucher_funding_source', 20)->nullable();
            $table->unsignedBigInteger('voucher_discount_minor')->nullable();
            $table->foreignId('shop_voucher_id')->nullable()->constrained('vouchers')->restrictOnDelete();
            $table->string('shop_voucher_funding_source', 20)->nullable();
            $table->unsignedBigInteger('shop_voucher_discount_minor')->nullable();
            $table->foreignId('platform_voucher_id')->nullable()->constrained('vouchers')->restrictOnDelete();
            $table->string('platform_voucher_funding_source', 20)->nullable();
            $table->unsignedBigInteger('platform_voucher_discount_minor')->nullable();
            $table->foreignId('shipping_voucher_id')->nullable()->constrained('vouchers')->restrictOnDelete();
            $table->string('shipping_voucher_funding_source', 20)->nullable();
            $table->unsignedBigInteger('shipping_voucher_discount_minor')->nullable();
            $table->unsignedBigInteger('shipping_discount_minor')->nullable();
            $table->unsignedBigInteger('seller_funded_discount_minor')->nullable();
            $table->unsignedBigInteger('marketplace_funded_discount_minor')->nullable();
            $table->string('buyer_note', 200)->nullable();
            $table->foreignId('pickup_session_id')->nullable()->constrained('pickup_sessions')->nullOnDelete();
            $table->string('pickup_window_key', 20)->nullable();
            $table->timestamp('pickup_window_starts_at')->nullable();
            $table->timestamp('pickup_window_ends_at')->nullable();
            $table->unsignedTinyInteger('pickup_missed_windows')->default(0);

            $table->index(['custody_provider_id', 'status'], 'seller_orders_custody_status_idx');
            $table->index('needs_reassignment', 'seller_orders_needs_reassignment_idx');
            $table->unique(['order_id', 'seller_id']);
            $table->index(['seller_id', 'status'], 'seller_orders_seller_status_idx');
            $table->index(['status', 'pickup_window_ends_at']);
            $table->unique('tracking_number');
            $table->foreign('custody_hub_id', 'seller_orders_custody_hub_fk')->references('id')->on('logistics_hubs')->restrictOnDelete();
            $table->foreign('custody_provider_id', 'seller_orders_custody_provider_fk')->references('id')->on('logistics_providers')->restrictOnDelete();
            $table->foreign('handoff_destination_provider_id', 'seller_orders_handoff_destination_fk')->references('id')->on('logistics_providers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        // Recreated when a pending drop migration is rolled back; it points at this table.
        Schema::dropIfExists('delivery_otps');

        Schema::dropIfExists('seller_orders');
    }
};
