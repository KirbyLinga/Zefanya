<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rider registration collects a residential address, but the previous schema
 * only kept the identity/vehicle columns. Logistics also needs to know which
 * Station Hub a rider belongs to before the rider can be assigned parcels.
 *
 * This migration is additive only: existing riders keep their data and stay
 * unassigned until Logistics selects a station.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $table): void {
            $table->string('address_mode', 20)->nullable()->after('contact_no');
            $table->string('province_code', 20)->nullable()->after('address_mode');
            $table->string('province_name', 120)->nullable()->after('province_code');
            $table->string('municipality_code', 20)->nullable()->after('province_name');
            $table->string('municipality_name', 120)->nullable()->after('municipality_code');
            $table->string('barangay_code', 20)->nullable()->after('municipality_name');
            $table->string('barangay_name', 120)->nullable()->after('barangay_code');
            $table->string('street', 255)->nullable()->after('barangay_name');
            $table->string('house_number', 50)->nullable()->after('street');
            $table->string('address_detail', 255)->nullable()->after('house_number');
            $table->string('postal_code', 20)->nullable()->after('address_detail');

            $table->foreignId('station_hub_id')->nullable()->after('logistics_provider_id')
                ->constrained('logistics_hubs')->restrictOnDelete();

            $table->text('status_reason')->nullable()->after('rejection_reason');
            $table->timestamp('status_changed_at')->nullable()->after('status_reason');

            $table->index(['logistics_provider_id', 'status'], 'riders_provider_status_idx');
            $table->index(['station_hub_id', 'status'], 'riders_station_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('riders', function (Blueprint $table): void {
            $table->dropIndex('riders_provider_status_idx');
            $table->dropIndex('riders_station_status_idx');
            $table->dropConstrainedForeignId('station_hub_id');
            $table->dropColumn([
                'address_mode',
                'province_code',
                'province_name',
                'municipality_code',
                'municipality_name',
                'barangay_code',
                'barangay_name',
                'street',
                'house_number',
                'address_detail',
                'postal_code',
                'status_reason',
                'status_changed_at',
            ]);
        });
    }
};
