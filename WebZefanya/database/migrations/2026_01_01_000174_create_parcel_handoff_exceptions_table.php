<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * parcel_handoff_exceptions - Problems found when no partner could take a parcel.
 *
 * Belongs to:
 *   - logistics_providers (origin_provider_id)
 *   - admins (resolved_by) - optional
 *   - seller_orders (seller_order_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('parcel_handoff_exceptions')) {
            return;
        }

        Schema::create('parcel_handoff_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('origin_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('status', 20)->default('OPEN');
            $table->string('destination_barangay_code', 20)->nullable();
            $table->text('reason');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['origin_provider_id', 'status'], 'handoff_exceptions_provider_idx');
            $table->index(['status', 'created_at'], 'handoff_exceptions_status_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('parcel_handoff_exceptions');
    }
};
