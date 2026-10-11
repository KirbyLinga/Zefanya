<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One selectable value per option axis (e.g. "Red", "M").
        Schema::create('product_option_values', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_option_id')
                ->constrained('product_options')
                ->cascadeOnDelete();

            // Display value, e.g. "Red", "M".
            $table->string('value', 100);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['product_option_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_values');
    }
};
