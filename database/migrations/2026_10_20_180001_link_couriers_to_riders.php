<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Couriers remain the single assignment entity (`seller_orders.courier_id` and
 * `delivery_courier_id`), but a Courier must now represent one approved Rider.
 * Linking instead of replacing keeps every existing assignment intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table): void {
            $table->foreignId('rider_id')->nullable()->unique()->after('logistics_provider_id')
                ->constrained('riders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('couriers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rider_id');
        });
    }
};
