<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * inventory_exceptions - Parcels found missing from a station count.
 *
 * Belongs to:
 *   - seller_orders (seller_order_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('inventory_exceptions')) {
            return;
        }

        Schema::create('inventory_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->string('status', 20)->default('OPEN');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['seller_order_id', 'status'], 'inventory_exceptions_order_status_idx');
            $table->index(['status', 'created_at'], 'inventory_exceptions_status_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('inventory_exceptions');
    }
};
