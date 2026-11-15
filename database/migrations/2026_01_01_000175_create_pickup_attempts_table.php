<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pickup_attempts - Each try to collect a parcel.
 *
 * Belongs to:
 *   - riders (rider_id)
 *   - seller_orders (seller_order_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('pickup_attempts')) {
            return;
        }

        Schema::create('pickup_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->foreignId('rider_id')->constrained('riders')->cascadeOnDelete();
            $table->string('failure_reason')->nullable();
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['seller_order_id', 'attempt_number']);
            $table->unique(['seller_order_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('pickup_attempts');
    }
};
