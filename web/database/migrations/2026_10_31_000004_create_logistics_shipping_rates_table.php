<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained()->cascadeOnDelete();
            $table->string('zone', 30);
            // Null means this company uses the marketplace rate for the zone.
            $table->unsignedInteger('first_kg_minor')->nullable();
            $table->unsignedInteger('additional_kg_minor')->nullable();
            $table->timestamps();

            $table->unique(['logistics_provider_id', 'zone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_shipping_rates');
    }
};
