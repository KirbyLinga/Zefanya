<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive columns on logistics_providers for mobile rider profiles,
     * vehicles, and document verification (Phase C1).
     */
    public function up(): void
    {
        Schema::table('logistics_providers', function (Blueprint $table) {
            $table->string('vehicle_type', 50)->nullable()->after('business_name');
            $table->string('plate_number', 50)->nullable()->after('vehicle_type');
            $table->string('license_number', 50)->nullable()->after('plate_number');
            $table->date('license_expiry')->nullable()->after('license_number');
            $table->string('license_path')->nullable()->after('upload_id_path');
            $table->string('or_cr_path')->nullable()->after('license_path');
            $table->string('hub', 100)->nullable()->after('address_detail');
            $table->boolean('is_online')->default(false)->after('status');
            $table->boolean('reverification_required')->default(false)->after('is_online');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_providers', function (Blueprint $table) {
            $table->dropColumn([
                'vehicle_type',
                'plate_number',
                'license_number',
                'license_expiry',
                'license_path',
                'or_cr_path',
                'hub',
                'is_online',
                'reverification_required',
            ]);
        });
    }
};
