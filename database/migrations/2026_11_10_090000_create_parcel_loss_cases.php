<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lost-parcel resolution.
 *
 * - parcel_loss_cases: one row per parcel Admin confirms LOST. It records the
 *   reason, the liable Logistics provider (from verified custody), the saved
 *   merchandise value owed to the Seller, the manual payment confirmation, and
 *   any later recovery. One row per parcel, so a repeated confirmation can
 *   never create a second obligation.
 * - parcel_loss_adjustments: signed, audited changes to a compensation that was
 *   already paid. A paid amount is never edited.
 * - manifests / parcel_handoffs . overdue_escalated_at: the one-time Admin
 *   escalation of a transfer that stays overdue.
 *
 * The LOST order status needs no schema change: status columns are VARCHAR.
 * Additive only; nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_loss_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->unsignedBigInteger('confirmed_by_admin_id');
            $table->timestamp('confirmed_at');
            $table->string('reason', 1000);

            // Liability: the provider holding verified custody when the parcel was lost.
            $table->foreignId('logistics_provider_id')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->string('liability_basis', 30);
            $table->unsignedBigInteger('custody_hub_id')->nullable();

            // Saved merchandise value only: no shipping, no commission.
            $table->unsignedBigInteger('merchandise_minor');
            $table->string('compensation_status', 20)->default('pending');
            $table->string('compensation_reference', 100)->nullable();
            $table->date('compensation_paid_on')->nullable();
            $table->timestamp('compensation_paid_at')->nullable();
            $table->unsignedBigInteger('compensation_paid_by_admin_id')->nullable();

            // Recovery: where a verified scan later found the parcel. Never reopens the order.
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

        Schema::create('parcel_loss_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parcel_loss_case_id')->constrained('parcel_loss_cases')->restrictOnDelete();
            $table->unsignedBigInteger('admin_id');
            $table->bigInteger('amount_minor');
            $table->string('reason', 1000);
            $table->timestamps();
        });

        Schema::table('manifests', function (Blueprint $table): void {
            $table->timestamp('overdue_escalated_at')->nullable()->after('overdue_notified_at');
        });
        Schema::table('parcel_handoffs', function (Blueprint $table): void {
            $table->timestamp('overdue_escalated_at')->nullable()->after('overdue_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_handoffs', fn (Blueprint $table) => $table->dropColumn('overdue_escalated_at'));
        Schema::table('manifests', fn (Blueprint $table) => $table->dropColumn('overdue_escalated_at'));
        Schema::dropIfExists('parcel_loss_adjustments');
        Schema::dropIfExists('parcel_loss_cases');
    }
};
