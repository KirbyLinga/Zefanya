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
        Schema::table('products', function (Blueprint $table) {
            $table->uuid('creation_key')->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('condition', 100)->nullable();
            $table->unique(['seller_id', 'creation_key'], 'product_seller_creation_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('product_seller_creation_unique');
            $table->dropColumn(['creation_key', 'brand', 'condition']);
        });
    }
};
