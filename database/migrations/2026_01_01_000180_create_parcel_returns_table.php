<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * parcel_returns - A buyer return and its whole lifecycle.
 *
 * Belongs to:
 *   - couriers (collection_courier_id) - optional
 *   - admins (fault_set_by_admin_id) - optional
 *   - logistics_hubs (origin_hub_id) - optional
 *   - logistics_providers (origin_provider_id) - optional
 *   - sellers (received_by_seller_id) - optional
 *   - seller_orders (seller_order_id) - one-to-one
 * Used by: logistics_return_charges, parcel_return_events, platform_refund_expenses
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('parcel_returns')) {
            return;
        }

        Schema::create('parcel_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->string('status', 40)->default('RETURN_REQUESTED');
            $table->unsignedTinyInteger('delivery_attempts')->default(0);
            $table->text('reason');
            $table->text('description')->nullable();
            $table->json('photos')->nullable();
            $table->text('exception_reason')->nullable();
            $table->foreignId('origin_provider_id')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('origin_hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
            $table->json('seller_address')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('seller_response_due_at')->nullable();
            $table->string('seller_decision', 20)->nullable();
            $table->string('seller_decision_reason', 300)->nullable();
            $table->timestamp('seller_decided_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->string('dispute_outcome', 20)->nullable();
            $table->string('dispute_note', 1000)->nullable();
            $table->timestamp('dispute_resolved_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_reference')->nullable();
            $table->unsignedBigInteger('refund_amount_minor')->nullable();
            $table->string('refund_recipient')->nullable();
            $table->date('refund_paid_on')->nullable();
            $table->string('refund_proof_path', 500)->nullable();
            $table->string('refund_reason', 1000)->nullable();
            $table->string('fault_party', 20)->nullable();
            $table->string('fault_note', 1000)->nullable();
            $table->foreignId('fault_set_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('fault_set_at')->nullable();
            $table->unsignedInteger('return_shipping_fee_minor')->nullable();
            $table->timestamp('origin_arrived_at')->nullable();
            $table->timestamp('seller_received_at')->nullable();
            $table->foreignId('received_by_seller_id')->nullable()->constrained('sellers')->restrictOnDelete();
            $table->string('handover_code_hash', 64)->nullable();
            $table->string('handover_nonce', 64)->nullable();
            $table->timestamp('handover_expires_at')->nullable();
            $table->string('stock_condition', 24)->default('pending_review');
            $table->timestamp('stock_restored_at')->nullable();
            $table->boolean('financial_review_required')->default(false);
            $table->timestamps();
            $table->foreignId('collection_courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->timestamp('collection_assigned_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->unsignedTinyInteger('collection_failed_attempts')->default(0);
            $table->timestamp('collection_last_failed_at')->nullable();
            $table->timestamp('collection_escalated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by_type', 20)->nullable();
            $table->unsignedBigInteger('closed_by_id')->nullable();
            $table->string('closed_reason', 1000)->nullable();
            $table->string('receipt_dispute_issue', 30)->nullable();
            $table->string('receipt_dispute_note', 1000)->nullable();
            $table->json('receipt_dispute_photos')->nullable();
            $table->timestamp('receipt_disputed_at')->nullable();
            $table->timestamp('earnings_restored_at')->nullable();
            $table->timestamp('collection_retry_authorized_at')->nullable();
            $table->unsignedBigInteger('collection_retry_authorized_by_id')->nullable();
            $table->string('disposition', 30)->nullable();
            $table->string('disposition_note', 1000)->nullable();
            $table->timestamp('disposition_set_at')->nullable();
            $table->unsignedBigInteger('disposition_set_by_admin_id')->nullable();
            $table->unsignedTinyInteger('refund_policy_version')->nullable();

            $table->index(['seller_decision', 'seller_response_due_at'], 'parcel_returns_decision_due_idx');
            $table->index(['origin_provider_id', 'status']);
            $table->unique('seller_order_id');
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('parcel_returns');
    }
};
