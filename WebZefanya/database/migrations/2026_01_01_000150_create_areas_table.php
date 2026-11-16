<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * areas - Barangays a station hub covers.
 *
 * Belongs to:
 *   - logistics_providers (logistics_provider_id)
 *   - logistics_hubs (station_hub_id) - optional
 * Used by: area_courier, couriers, seller_orders
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('areas')) {
            return;
        }

        Schema::create('areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->cascadeOnDelete();
            $table->foreignId('station_hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
            $table->string('name');
            $table->string('municipality');
            $table->string('city_municipality_code', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->string('barangay_code', 20)->nullable();
            $table->string('barangay_name', 120)->nullable();
            $table->timestamp('coverage_validated_at')->nullable();
            $table->string('active_barangay_code', 20)->nullable()->virtualAs('CASE WHEN is_active = 1 THEN barangay_code ELSE NULL END');

            $table->unique(['logistics_provider_id', 'active_barangay_code'], 'areas_provider_barangay_unique');
            $table->index('logistics_provider_id', 'areas_provider_coverage_index');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('areas');
    }
};
