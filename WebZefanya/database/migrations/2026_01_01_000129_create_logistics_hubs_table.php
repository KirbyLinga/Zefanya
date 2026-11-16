<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * logistics_hubs - A company's province hubs and the station hubs under them.
 *
 * Belongs to:
 *   - logistics_hubs (parent_hub_id + logistics_provider_id) [itself] - optional
 *   - logistics_providers (logistics_provider_id)
 * Used by: areas, logistics_hub_location_changes, manifest_items, manifests, parcel_handoffs,
 *   parcel_returns, parcel_routes, riders, seller_orders, transport_connections
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('logistics_hubs')) {
            return;
        }

        Schema::create('logistics_hubs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedBigInteger('parent_hub_id')->nullable();
            $table->string('name', 120);
            $table->string('hub_type', 20);
            $table->string('region_code', 20)->nullable();
            $table->string('region_name', 120)->nullable();
            $table->string('province_code', 20)->nullable();
            $table->string('province_name', 120);
            $table->string('city_municipality_code', 20)->nullable();
            $table->string('municipality', 120)->nullable();
            $table->string('barangay_code', 20)->nullable();
            $table->string('barangay_name', 120)->nullable();
            $table->string('street')->nullable();
            $table->string('building')->nullable();
            $table->string('landmark')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('location_details')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('approval_status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedTinyInteger('active_province_slot')->nullable();
            $table->timestamps();

            $table->index(['approval_status', 'is_active'], 'hubs_approval_active_idx');
            $table->unique(['id', 'logistics_provider_id'], 'hubs_id_provider_unique');
            $table->unique(['logistics_provider_id', 'active_province_slot'], 'hubs_one_active_province_uq');
            $table->index(['logistics_provider_id', 'hub_type', 'is_active']);
            $table->foreign(['parent_hub_id', 'logistics_provider_id'], 'hubs_parent_provider_fk')->references(['id', 'logistics_provider_id'])->on('logistics_hubs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('logistics_hubs');
    }
};
