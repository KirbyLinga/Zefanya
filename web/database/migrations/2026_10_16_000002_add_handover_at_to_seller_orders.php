<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the seller → rider handover moment for a parcel. The pickup courier
 * is already stored on `seller_orders.courier_id`; this timestamp is the
 * missing half of the handover record, and it is printed on the waybill so the
 * seller can prove when the parcel left the shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->timestamp('handover_at')->nullable()->after('delivery_failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropColumn('handover_at');
        });
    }
};
