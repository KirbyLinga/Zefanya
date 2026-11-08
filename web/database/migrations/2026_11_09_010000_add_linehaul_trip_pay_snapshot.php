<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linehaul_assignments', function (Blueprint $table): void {
            $table->unsignedInteger('trip_pay_minor')->nullable();
            $table->string('trip_pay_vehicle_type', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('linehaul_assignments', fn (Blueprint $table) => $table->dropColumn(['trip_pay_minor', 'trip_pay_vehicle_type']));
    }
};
