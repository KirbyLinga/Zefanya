<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payments - The cash-on-delivery amount due for a parcel.
 *
 * Belongs to:
 *   - seller_orders (seller_order_id) - one-to-one
 * Used by: cod_remittances, platform_refund_expenses, seller_settlements
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('payments')) {
            return;
        }

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->unsignedInteger('amount_minor');
            $table->string('method', 20)->default('cod');
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->string('collected_by_type', 20)->nullable();
            $table->unsignedBigInteger('collected_by_id')->nullable();
            $table->unsignedBigInteger('collected_amount_minor')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();

            $table->unique('seller_order_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('payments');
    }
};
