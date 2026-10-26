<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller stock edits have no order, so ledger rows need a nullable
 * seller_order_id. No-op when the column is already nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $column = collect(Schema::getColumns('inventory_transactions'))
            ->firstWhere('name', 'seller_order_id');

        if ($column !== null && ! $column['nullable']) {
            Schema::table('inventory_transactions', function (Blueprint $table): void {
                $table->unsignedBigInteger('seller_order_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: tightening back to NOT NULL would fail on real ledger rows.
    }
};
