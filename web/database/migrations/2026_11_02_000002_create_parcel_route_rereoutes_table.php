<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_route_rereoutes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_order_id');
            $table->unsignedBigInteger('original_route_id');
            $table->unsignedBigInteger('new_route_id');
            $table->string('reason');
            $table->string('triggered_by')->nullable();  // 'connection_unavailable', 'hub_inactive', 'manual_request'
            $table->json('failed_connection')->nullable();  // stores failed connection details
            $table->timestamps();

            $table->foreign('seller_order_id')->references('id')->on('seller_orders')->onDelete('cascade');
            $table->foreign('original_route_id')->references('id')->on('parcel_routes')->onDelete('restrict');
            $table->foreign('new_route_id')->references('id')->on('parcel_routes')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_route_rereoutes');
    }
};
