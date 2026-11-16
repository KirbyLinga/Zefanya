<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * parcel_provider_allocations - Records of each company that handled a parcel when three or more did.
 *
 * Belongs to:
 *   - logistics_providers (logistics_provider_id)
 *   - allocation_policy_approvals (policy_approval_id) - optional
 *   - seller_orders (seller_order_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('parcel_provider_allocations')) {
            return;
        }

        Schema::create('parcel_provider_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('role', 20);
            $table->unsignedSmallInteger('sequence');
            $table->unsignedBigInteger('share_minor')->nullable();
            $table->string('status', 20)->default('pending_policy');
            $table->foreignId('policy_approval_id')->nullable()->constrained('allocation_policy_approvals')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['seller_order_id', 'logistics_provider_id'], 'parcel_allocations_parcel_provider_unique');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('parcel_provider_allocations');
    }
};
