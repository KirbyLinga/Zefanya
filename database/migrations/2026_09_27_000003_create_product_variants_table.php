<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One purchasable combination per row. Strategy (a): price/stock live
        // HERE, and every existing product receives an auto-generated "Default"
        // variant backfilled from its products.price/stock_quantity (next
        // migration), so legacy single-SKU behaviour is preserved unchanged.
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Optional seller-facing SKU. Nullable (auto Default rows carry
            // none — multiple NULLs never collide) and indexed for search/lookup.
            // Uniqueness is scoped per-seller at the application layer, so two
            // different sellers can use the same code.
            $table->string('sku', 64)->nullable();

            $table->decimal('price', 10, 2);
            $table->unsignedInteger('stock_quantity')->default(0);
            // Per-variant kill switch for a single combination (product.status
            // remains the product-level control).
            $table->boolean('is_available')->default(true);

            // Optional per-variant parcel data. NULL = inherit the product's
            // weight_grams/dimensions (the common case; most variants of a
            // product ship in the same box).
            $table->unsignedInteger('weight_grams')->nullable();
            $table->json('dimensions')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'is_available']);
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
