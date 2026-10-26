<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the origin Station Hub on each parcel when pickup routing is
 * established so later coverage changes cannot redirect in-flight shipments.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('seller_orders', 'origin_station_hub_id')) {
            Schema::table('seller_orders', function (Blueprint $table): void {
                $table->foreignId('origin_station_hub_id')
                    ->nullable()
                    ->after('destination_area_id')
                    ->constrained('logistics_hubs')
                    ->nullOnDelete();
            });
        }

        // Current rider stations and coverage are not historical evidence.
        // Leave legacy origins null for resolution from original routing records.
    }

    public function down(): void
    {
        if (Schema::hasColumn('seller_orders', 'origin_station_hub_id')) {
            Schema::table('seller_orders', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('origin_station_hub_id');
            });
        }
    }
};
