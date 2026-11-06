<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_providers', function (Blueprint $table): void {
            // Admin-granted window in which a SUSPENDED provider's riders may
            // only finish parcels already out for delivery. Null = no window.
            $table->timestamp('rider_wind_down_until')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_providers', function (Blueprint $table): void {
            $table->dropColumn('rider_wind_down_until');
        });
    }
};
