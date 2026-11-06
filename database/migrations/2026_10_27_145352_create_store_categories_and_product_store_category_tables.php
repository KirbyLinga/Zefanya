<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('store_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('store_categories')->restrictOnDelete();
            $table->string('name', 50);
            $table->string('normalized_name', 50);
            $table->timestamps();
            $table->unique(['seller_id', 'normalized_name']);
        });
        Schema::create('product_store_category', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('store_category_id')->constrained('store_categories')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['product_id', 'store_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_store_category');
        Schema::dropIfExists('store_categories');
    }
};
