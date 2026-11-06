<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            // Scan-verified Station Hub receipt of a parcel brought back after
            // a failed delivery attempt. Only a receipt newer than the latest
            // failure counts; until then the parcel is still with the Rider.
            $table->timestamp('returned_to_station_at')->nullable()->after('delivery_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropColumn('returned_to_station_at');
        });
    }
};
