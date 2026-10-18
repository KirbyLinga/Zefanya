<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assign a delivery job to a logistics provider.
     *
     * ## Why this is on seller_orders, not a separate table
     *
     * A seller_order is already the unit of physical fulfilment: one parcel,
     * one seller, one buyer address. A separate `assignments` table would
     * duplicate that relationship. `assigned_courier_id` turns it into a job
     * simply by naming who carries it — null means unassigned, a value means
     * dispatched to that rider.
     *
     * ## The fee column
     *
     * The delivery fee is set when a dispatcher assigns the job (or when the
     * ERP calculates it from the distance/package-size formula). It is stored
     * here rather than computed on-the-fly because the app's earnings screen
     * shows what was *agreed* at dispatch, not a re-derived estimate that can
     * drift as the formula changes. The same principle as `price_minor` on
     * order_items: a snapshot of the agreed amount at the moment the decision
     * was made.
     */
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            // The courier who has been assigned this parcel. Null until a
            // dispatcher assigns one. SET NULL on delete: if a logistics
            // provider account is removed, the parcel becomes unassigned
            // rather than orphaned — which surfaces it for reassignment
            // instead of hiding it permanently.
            $table->foreignId('assigned_courier_id')
                ->nullable()
                ->after('seller_id')
                ->constrained('logistics_providers')
                ->nullOnDelete();

            // What the courier is paid for this delivery, in centavos.
            // Null until set at dispatch time; a dispatcher who forgets
            // the fee gets a 422 at the assignment endpoint, not a ₱0
            // order quietly written.
            $table->unsignedInteger('delivery_fee_minor')
                ->nullable()
                ->after('assigned_courier_id');

            // The area / district the parcel goes to — shown on the job card.
            // Derived from orders.shipping_address at assignment time and
            // snapshotted here so a later address edit cannot change what the
            // courier was told to deliver to.
            $table->string('delivery_area')->nullable()->after('delivery_fee_minor');
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropForeign(['assigned_courier_id']);
            $table->dropColumn(['assigned_courier_id', 'delivery_fee_minor', 'delivery_area']);
        });
    }
};
