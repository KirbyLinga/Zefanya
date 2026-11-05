<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery areas used to assign parcels to riders by area (AREA-A/B/C).
     * HUB-QC is the sorting center.
     */
    public function up(): void
    {
        Schema::create('delivery_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique(); // e.g. AREA-A
            $table->string('name');
            $table->text('covers'); // e.g. "Quezon City (ADDR-1)"
            $table->foreignId('logistics_company_id')->nullable()->constrained('logistics_providers');
            $table->foreignId('assigned_rider_id')->nullable()->constrained('logistics_providers');
            $table->boolean('is_sorting_center')->default(false); // HUB-QC
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_areas');
    }
};
