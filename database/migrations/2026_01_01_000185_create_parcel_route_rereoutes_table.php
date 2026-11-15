<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * parcel_route_rereoutes - Record of a parcel's route being replaced.
 *
 * Belongs to:
 *   - incidents (incident_id) - optional
 *   - parcel_routes (new_route_id)
 *   - parcel_routes (original_route_id)
 *   - seller_orders (seller_order_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('parcel_route_rereoutes')) {
            return;
        }

        Schema::create('parcel_route_rereoutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->foreignId('original_route_id')->constrained('parcel_routes')->restrictOnDelete();
            $table->foreignId('new_route_id')->constrained('parcel_routes')->restrictOnDelete();
            $table->string('reason');
            $table->string('triggered_by')->nullable();
            $table->json('failed_connection')->nullable();
            $table->json('previous_path')->nullable();
            $table->json('new_path')->nullable();
            $table->json('completed_legs')->nullable();
            $table->string('authorized_by_type', 32)->nullable();
            $table->unsignedBigInteger('authorized_by_id')->nullable();
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->timestamp('rerouted_at')->nullable();
            $table->timestamps();

            $table->index(['seller_order_id', 'rerouted_at'], 'rereoutes_parcel_time_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('parcel_route_rereoutes');
    }
};
