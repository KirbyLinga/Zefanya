<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspending/deactivating a rider must never rewrite the parcel status graph.
 * Instead the parcel is flagged for Logistics to reassign through the existing
 * sorting/assignment screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->boolean('needs_reassignment')->default(false)->after('delivery_failure_reason');
            $table->text('reassignment_reason')->nullable()->after('needs_reassignment');
            $table->timestamp('reassignment_flagged_at')->nullable()->after('reassignment_reason');

            $table->index('needs_reassignment', 'seller_orders_needs_reassignment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropIndex('seller_orders_needs_reassignment_idx');
            $table->dropColumn(['needs_reassignment', 'reassignment_reason', 'reassignment_flagged_at']);
        });
    }
};
