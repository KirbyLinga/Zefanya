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
        Schema::create('shipping_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('origin_zone', 20);
            $table->string('destination_zone', 20);
            $table->unsignedInteger('first_kg_minor');
            $table->unsignedInteger('additional_kg_minor');
            $table->unique(['origin_zone', 'destination_zone']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
