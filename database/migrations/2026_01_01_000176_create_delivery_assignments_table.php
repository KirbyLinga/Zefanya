<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * delivery_assignments - History of delivery rider assignments for a parcel.
 *
 * Belongs to:
 *   - couriers (courier_id) - optional
 *   - seller_orders (seller_order_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('delivery_assignments')) {
            return;
        }

        Schema::create('delivery_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            $table->string('role', 12);
            $table->string('assigned_by_type', 20)->nullable();
            $table->unsignedBigInteger('assigned_by_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['seller_order_id', 'role']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('delivery_assignments');
    }
};
