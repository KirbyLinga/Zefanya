<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * seller_debts - Money a seller owes after refunds on paid-out earnings.
 *
 * Belongs to:
 *   - sellers (seller_id)
 *   - seller_orders (seller_order_id) - one-to-one
 *   - seller_settlements (seller_settlement_id) - optional
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('seller_debts')) {
            return;
        }

        Schema::create('seller_debts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('seller_settlement_id')->nullable()->constrained('seller_settlements')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('settled_minor')->default(0);
            $table->string('reason', 100);
            $table->timestamps();

            $table->index(['seller_id', 'settled_minor']);
            $table->unique('seller_order_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('seller_debts');
    }
};
