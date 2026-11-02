<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('origin_hub_id');
            $table->unsignedBigInteger('destination_hub_id');
            $table->enum('transport_mode', ['ROAD', 'SEA', 'AIR'])->default('ROAD');
            $table->unsignedBigInteger('operating_provider_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->foreign('origin_hub_id')->references('id')->on('logistics_hubs')->onDelete('restrict');
            $table->foreign('destination_hub_id')->references('id')->on('logistics_hubs')->onDelete('restrict');
            $table->foreign('operating_provider_id')->references('id')->on('logistics_providers')->onDelete('set null');

            $table->unique(['origin_hub_id', 'destination_hub_id'], 'unique_transport_connection');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_connections');
    }
};
