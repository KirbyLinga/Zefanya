<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->string('recipient');
            $table->string('phone', 20);
            $table->string('label', 20)->default('Home'); // Home, Work, Other
            $table->string('region');
            $table->string('region_code', 10)->nullable();
            $table->string('province')->nullable(); // Empty/null for NCR
            $table->string('province_code', 10)->nullable();
            $table->string('city');
            $table->string('city_code', 10)->nullable();
            $table->string('barangay');
            $table->string('barangay_code', 10)->nullable();
            $table->string('street');
            $table->string('postal_code', 10)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['buyer_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_addresses');
    }
};
