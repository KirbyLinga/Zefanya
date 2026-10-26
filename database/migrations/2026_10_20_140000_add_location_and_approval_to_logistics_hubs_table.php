<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_hubs', function (Blueprint $table): void {
            $table->string('region_code', 20)->nullable()->after('hub_type');
            $table->string('region_name', 120)->nullable()->after('region_code');
            $table->string('province_code', 20)->nullable()->after('region_name');
            $table->string('city_municipality_code', 20)->nullable()->after('province_name');
            $table->string('barangay_code', 20)->nullable()->after('municipality');
            $table->string('barangay_name', 120)->nullable()->after('barangay_code');
            $table->string('street', 255)->nullable()->after('barangay_name');
            $table->string('building', 255)->nullable()->after('street');
            $table->string('landmark', 255)->nullable()->after('building');
            $table->string('postal_code', 20)->nullable()->after('landmark');

            $table->string('approval_status', 20)->default('pending')->after('is_active');
            $table->text('rejection_reason')->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('rejection_reason');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->unsignedBigInteger('approved_by')->nullable()->after('rejected_at');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('approved_by');

            $table->index(['approval_status', 'is_active'], 'hubs_approval_active_idx');
        });

        // Safe migration/backfill policy: preserve any existing hubs as approved.
        DB::table('logistics_hubs')->update([
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('logistics_hubs', function (Blueprint $table): void {
            $table->dropIndex('hubs_approval_active_idx');
            $table->dropColumn([
                'region_code',
                'region_name',
                'province_code',
                'city_municipality_code',
                'barangay_code',
                'barangay_name',
                'street',
                'building',
                'landmark',
                'postal_code',
                'approval_status',
                'rejection_reason',
                'approved_at',
                'rejected_at',
                'approved_by',
                'rejected_by',
            ]);
        });
    }
};
