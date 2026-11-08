<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_plate_locks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('vehicle_plate_locks')->insert(['id' => 1]);
        Schema::create('linehaul_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('linehaul_driver_id')->unique()->constrained('linehaul_drivers')->restrictOnDelete();
            $table->string('plate_number', 20)->unique();
            $table->string('license_no', 20);
            $table->date('license_expiry');
            $table->json('license_codes');
            $table->string('vehicle_type', 60);
            $table->decimal('capacity_kg', 10, 2);
            $table->json('documents');
            $table->string('review_status', 20)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linehaul_profiles');
        Schema::dropIfExists('vehicle_plate_locks');
    }
};
