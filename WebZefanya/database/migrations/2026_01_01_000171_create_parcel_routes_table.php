<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * parcel_routes - The saved path a parcel must follow through the hubs.
 *
 * Belongs to:
 *   - logistics_hubs (destination_hub_id)
 *   - logistics_hubs (origin_hub_id)
 *   - seller_orders (seller_order_id) - one-to-one
 * Used by: parcel_route_rereoutes
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('parcel_routes')) {
            return;
        }

        Schema::create('parcel_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->foreignId('origin_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('destination_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->unsignedInteger('current_leg_index')->default(0);
            $table->json('legs');
            $table->timestamp('route_planned_at')->useCurrent();
            $table->timestamp('route_completed_at')->nullable();
            $table->timestamps();

            $table->unique('seller_order_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('parcel_routes');
    }
};
