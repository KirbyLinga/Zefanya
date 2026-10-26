<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_hub_location_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_hub_id')->constrained('logistics_hubs')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');

            $table->string('region_code', 20)->nullable();
            $table->string('region_name', 120)->nullable();
            $table->string('province_code', 20)->nullable();
            $table->string('province_name', 120)->nullable();
            $table->string('city_municipality_code', 20)->nullable();
            $table->string('municipality', 120)->nullable();
            $table->string('barangay_code', 20)->nullable();
            $table->string('barangay_name', 120)->nullable();
            $table->string('street', 255)->nullable();
            $table->string('building', 255)->nullable();
            $table->string('landmark', 255)->nullable();
            $table->string('postal_code', 20)->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['logistics_hub_id', 'status'], 'hub_location_changes_hub_status_idx');
            $table->index(['status', 'requested_at'], 'hub_location_changes_status_requested_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_hub_location_changes');
    }
};
