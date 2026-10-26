<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counts how many times a delivery has been attempted. Per the agreed default:
 * up to 2 retries (reschedule) are allowed; on the 3rd failure the parcel can
 * only be RETURNED to the seller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->unsignedTinyInteger('delivery_attempts')->default(0)->after('delivery_failure_notes');
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropColumn('delivery_attempts');
        });
    }
};
