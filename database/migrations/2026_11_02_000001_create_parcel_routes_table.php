<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_order_id')->unique();
            $table->unsignedBigInteger('origin_hub_id');
            $table->unsignedBigInteger('destination_hub_id');
            $table->unsignedInteger('current_leg_index')->default(0);
            $table->json('legs');  // Ordered array of route legs: [{hub_id, transport_mode, expected_arrival_at}, ...]
            $table->timestamp('route_planned_at')->useCurrent();
            $table->timestamp('route_completed_at')->nullable();
            $table->timestamps();

            $table->foreign('seller_order_id')->references('id')->on('seller_orders')->onDelete('cascade');
            $table->foreign('origin_hub_id')->references('id')->on('logistics_hubs')->onDelete('restrict');
            $table->foreign('destination_hub_id')->references('id')->on('logistics_hubs')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_routes');
    }
};
