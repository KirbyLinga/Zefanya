<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One option axis per product (e.g. "Color", "Size"). Deliberately not
        // unique by name at the DB level — case-sensitivity across collations
        // is unreliable; the app layer enforces one name per product.
        Schema::create('product_options', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Display name, e.g. "Color", "Size".
            $table->string('name', 50);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_options');
    }
};
