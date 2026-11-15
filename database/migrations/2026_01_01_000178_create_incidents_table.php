<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * incidents - Reported problems with a parcel (missing, damaged).
 *
 * Belongs to:
 *   - seller_orders (incident_seller_order_id)
 * Used by: incident_events, parcel_route_rereoutes
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('incidents')) {
            return;
        }

        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('reporter_id')->nullable();
            $table->string('reporter_type', 32);
            $table->string('type', 24);
            $table->string('status', 24)->default('open');
            $table->unsignedInteger('blocked_leg_index')->nullable();
            $table->string('resolution', 40)->nullable();
            $table->json('resolution_context')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by_type', 32)->nullable();
            $table->unsignedBigInteger('reviewed_by_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by_type', 32)->nullable();
            $table->unsignedBigInteger('resolved_by_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('happened_at')->nullable();
            $table->timestamps();
            $table->string('reason')->nullable();
            $table->json('custody_snapshot')->nullable();
            $table->string('photo_disk', 32)->nullable();
            $table->string('photo_path')->nullable();

            $table->index(['incident_seller_order_id', 'status']);
            $table->index(['incident_seller_order_id', 'resolution'], 'incidents_parcel_resolution_idx');
            $table->index(['status', 'type'], 'incidents_status_type_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('incidents');
    }
};
