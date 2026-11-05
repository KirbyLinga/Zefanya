<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shipping rate table seeded from the spec (Test Data (Modules) sheet).
     * Rate is charged per seller order: first kg plus each additional kg,
     * rounded up to the next whole kg (chargeable weight).
     */
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('route'); // e.g. "Within NCR", "Visayas to NCR"
            $table->unsignedInteger('first_kg_centavos'); // first kg
            $table->unsignedInteger('additional_kg_centavos'); // each additional kg
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
