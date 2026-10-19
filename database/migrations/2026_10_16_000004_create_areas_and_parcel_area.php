<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery areas, scoped to a logistics provider.
 *
 * The sorting center's second duty is "assign by the rider's area", which the
 * apps had no way to express: there was no area concept at all. This adds
 *   - `areas`             one row per coverage area of a provider,
 *   - `couriers.area_id`  the area a rider serves (rider → area link),
 *   - `seller_orders.destination_area_id`  the area a parcel is bound for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->cascadeOnDelete();
            $table->string('name');
            // The municipality/city this area covers; matched against the
            // parcel's destination (and a rider's service area).
            $table->string('municipality');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['logistics_provider_id', 'municipality']);
        });

        Schema::table('couriers', function (Blueprint $table): void {
            $table->foreignId('area_id')->nullable()->after('logistics_provider_id')->constrained('areas')->nullOnDelete();
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->foreignId('destination_area_id')->nullable()->after('handover_at')->constrained('areas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('destination_area_id');
        });

        Schema::table('couriers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('area_id');
        });

        Schema::dropIfExists('areas');
    }
};
