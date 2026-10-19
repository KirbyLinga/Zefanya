<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds parcel-weight + dimension fields to `products`.
     *
     * Weight and dimensions live on the product (not the order line) because a
     * parcel's bulk is derived from what the buyer purchased. Existing products
     * get a 0/null default so the column is non-breaking.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('weight_grams')->default(0)->after('stock_quantity');
            $table->json('dimensions')->nullable()->after('weight_grams');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['weight_grams', 'dimensions']);
        });
    }
};
