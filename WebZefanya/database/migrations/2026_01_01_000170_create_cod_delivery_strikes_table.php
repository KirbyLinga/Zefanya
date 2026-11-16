<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * cod_delivery_strikes - Strikes recorded against a buyer who refuses a cash-on-delivery parcel.
 *
 * Belongs to:
 *   - buyers (buyer_id)
 *   - seller_orders (seller_order_id) - one-to-one
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('cod_delivery_strikes')) {
            return;
        }

        Schema::create('cod_delivery_strikes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('buyers')->restrictOnDelete();
            $table->string('phone_key', 10)->nullable();
            $table->string('reason', 30);
            $table->timestamp('failed_at');
            $table->timestamps();

            $table->index(['buyer_id', 'failed_at']);
            $table->index(['phone_key', 'failed_at']);
            $table->unique('seller_order_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('cod_delivery_strikes');
    }
};
