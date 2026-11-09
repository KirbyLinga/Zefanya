<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('linehaul_breakdown_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('linehaul_trip_delay_id')->unique()->constrained('linehaul_trip_delays')->restrictOnDelete();
            $table->string('photo_path');
            $table->char('photo_hash', 64);
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('linehaul_breakdown_reports');
    }
};
