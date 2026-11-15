<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * logistics_provider_links - Partnership from one delivery company to another (one direction per row).
 *
 * Belongs to:
 *   - admins (approved_by) - optional
 *   - logistics_providers (destination_consented_by) - optional
 *   - logistics_providers (destination_provider_id)
 *   - logistics_providers (origin_consented_by) - optional
 *   - logistics_providers (origin_provider_id)
 *   - logistics_providers (rejected_by) - optional
 *   - admins (revoked_by) - optional
 * Used by: parcel_handoffs
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('logistics_provider_links')) {
            return;
        }

        Schema::create('logistics_provider_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('origin_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('destination_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedInteger('priority')->default(0);
            $table->string('status', 20)->default('PENDING');
            $table->foreignId('origin_consented_by')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->timestamp('origin_consented_at')->nullable();
            $table->foreignId('destination_consented_by')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->timestamp('destination_consented_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unsignedTinyInteger('active_slot')->nullable()->storedAs('case when status = \'ACTIVE\' then 1 else null end');
            $table->foreignId('rejected_by')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 500)->nullable();

            $table->unique(['origin_provider_id', 'destination_provider_id', 'active_slot'], 'provider_links_active_pair_unique');
            $table->index(['destination_provider_id', 'status'], 'provider_links_incoming_idx');
            $table->index(['origin_provider_id', 'status', 'priority'], 'provider_links_outgoing_idx');
            $table->unique(['id', 'origin_provider_id', 'destination_provider_id'], 'provider_links_route_unique');
            $table->index(['status', 'created_at'], 'provider_links_status_created_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('logistics_provider_links');
    }
};
