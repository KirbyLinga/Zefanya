<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * manifest_items - The parcels inside a manifest.
 *
 * Belongs to:
 *   - manifests (manifest_id)
 *   - logistics_hubs (recovered_hub_id) - optional
 *   - seller_orders (seller_order_id)
 * Used by: manifest_audit_logs
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('manifest_items')) {
            return;
        }

        Schema::create('manifest_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manifest_id')->constrained('manifests')->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->string('tracking_number', 64)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->foreignId('recovered_hub_id')->nullable()->constrained('logistics_hubs')->restrictOnDelete();
            $table->string('recovered_by_type', 20)->nullable();
            $table->unsignedBigInteger('recovered_by_id')->nullable();
            $table->string('received_by_type', 20)->nullable();
            $table->unsignedBigInteger('received_by_id')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('issue_type', 20)->nullable();
            $table->text('issue_note')->nullable();
            $table->timestamp('issue_recorded_at')->nullable();
            $table->timestamp('verified_for_dispatch_at')->nullable();
            $table->string('verified_for_dispatch_by_type', 20)->nullable();
            $table->unsignedBigInteger('verified_for_dispatch_by_id')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('active_seller_order_id')->nullable()->storedAs('case when released_at is null then seller_order_id else null end');

            $table->unique('active_seller_order_id', 'manifest_items_active_parcel_unique');
            $table->index(['manifest_id', 'received_at'], 'manifest_items_receipt_idx');
            $table->index('seller_order_id', 'manifest_items_seller_order_idx');
            $table->index('tracking_number', 'manifest_items_tracking_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('manifest_items');
    }
};
