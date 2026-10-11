<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cart line targets one specific variant; NULL keeps legacy
     * pre-variant cart lines working during the transition. The old
     * unique(buyer_id, product_id) becomes unique(buyer_id, product_id,
     * variant_id) so one buyer can hold "Red" and "Blue" of the same product.
     */
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreignId('variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_variants')
                ->cascadeOnDelete();

            // Add the replacement FIRST: the two-column unique is the only
            // index MySQL can use for cart_items_buyer_id_foreign, so dropping
            // it before a buyer_id-leading index exists fails with error 1553
            // ("Cannot drop index ... needed in a foreign key constraint").
            $table->unique(['buyer_id', 'product_id', 'variant_id']);
            $table->dropUnique('cart_items_buyer_id_product_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            // Mirror of up(): recreate the two-column unique BEFORE dropping
            // the three-column one, so the buyer FK is never left without a
            // supporting index (error 1553).
            $table->unique(['buyer_id', 'product_id']);
            $table->dropUnique('cart_items_buyer_id_product_id_variant_id_unique');
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('variant_id');
        });
    }
};
