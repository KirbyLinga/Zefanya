<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The append-only ledger records which variant's stock moved (checkout
        // decrements VARIANTS now, not the product row). Nullable: rows
        // written before variants existed keep product-level semantics.
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->foreignId('variant_id')
                ->nullable()
                ->after('seller_order_id')
                ->constrained('product_variants')
                ->nullOnDelete();

            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('variant_id');
            $table->dropIndex(['variant_id']);
        });
    }
};
