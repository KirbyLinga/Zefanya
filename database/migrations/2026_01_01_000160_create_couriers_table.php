<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * couriers - A rider's work record inside a company (one per rider).
 *
 * Belongs to:
 *   - areas (area_id) - optional
 *   - logistics_providers (logistics_provider_id)
 *   - riders (rider_id) - one-to-one - optional
 * Used by: area_courier, cod_remittances, delivery_assignments, delivery_proofs, parcel_returns,
 *   pickup_sessions, rider_earnings, rider_payouts, seller_orders
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('couriers')) {
            return;
        }

        Schema::create('couriers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('riders')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->string('name');
            $table->string('phone', 11);
            $table->json('service_area');
            $table->boolean('is_available')->default(true);
            $table->string('vehicle_type', 20)->default('motorcycle');
            $table->unsignedInteger('max_weight_grams')->default(5000);
            $table->timestamps();

            $table->index(['is_available', 'vehicle_type']);
            $table->index('logistics_provider_id');
            $table->unique('rider_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('couriers');
    }
};
