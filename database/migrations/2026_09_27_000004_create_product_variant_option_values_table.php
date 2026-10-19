<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which option values make up a variant (the Cartesian combination).
        Schema::create('product_variant_option_values', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->cascadeOnDelete();

            $table->foreignId('product_option_value_id')
                ->constrained('product_option_values')
                ->cascadeOnDelete();

            $table->timestamps();

            // A value belongs to a combination exactly once. Values themselves
            // ARE shared across combinations ("Red" is in both "Red / M" and
            // "Red / L"), so the value_id column must NOT be unique — per-
            // product value scoping is guaranteed by the option/value tables.
            //
            // The index name is explicit because the auto-generated one
            // (product_variant_option_values_product_variant_id_product_option_value_id_unique)
            // is 79 characters and MySQL rejects identifiers over 64.
            $table->unique(['product_variant_id', 'product_option_value_id'], 'product_variant_option_values_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_option_values');
    }
};
