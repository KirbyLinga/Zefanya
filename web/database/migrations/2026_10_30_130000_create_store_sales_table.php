<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Null = the whole product. Cascade, never null-on-delete: losing the
            // variant must not silently widen the sale to every other variant.
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            // VARCHAR, never a native MySQL ENUM (project rule #6). Values are
            // constrained by App\Enums\StoreSaleType and cast on the model.
            $table->string('type');
            // Whole percent off (1-99) for percent type; sale price in centavos for fixed_price
            $table->unsignedBigInteger('value');
            // No status column: Upcoming/Ongoing/Ended derive from these two times.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();

            $table->index(['seller_id', 'ends_at']);
            $table->index(['product_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_sales');
    }
};
