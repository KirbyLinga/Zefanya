<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds tracking + courier linkage to seller_orders so each per-seller
     * fulfilment unit can carry an independent tracking number and rider.
     */
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->string('tracking_number', 64)->nullable()->unique()->after('status');
            $table->foreignId('courier_id')
                ->nullable()
                ->after('tracking_number')
                ->constrained('couriers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeign(['courier_id']);
            $table->dropColumn(['tracking_number', 'courier_id']);
        });
    }
};
