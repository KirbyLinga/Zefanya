<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('percent')->nullable();
            $table->unsignedSmallInteger('buy_quantity')->nullable();
            $table->unsignedSmallInteger('get_quantity')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();

            $table->index(['seller_id', 'starts_at', 'ends_at']);
        });

        Schema::create('seller_promotion_product', function (Blueprint $table) {
            $table->foreignId('seller_promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['seller_promotion_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_promotion_product');
        Schema::dropIfExists('seller_promotions');
    }
};
