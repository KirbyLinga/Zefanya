<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            // The return trip's cost, frozen when the return is approved: the same route,
            // weight and carrier as the original delivery, at the checkout rate.
            $table->unsignedInteger('return_shipping_fee_minor')->nullable()->after('fault_set_at');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->dropColumn('return_shipping_fee_minor');
        });
    }
};
