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
        Schema::create('pickup_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_order_id');
            $table->foreign('seller_order_id')->references('id')->on('seller_orders')->onDelete('cascade');
            $table->unsignedTinyInteger('attempt_number');
            $table->unsignedBigInteger('rider_id');
            $table->foreign('rider_id')->references('id')->on('riders')->onDelete('cascade');
            $table->string('failure_reason')->nullable();
            $table->timestamp('failed_at')->useCurrent();
            $table->index(['seller_order_id', 'attempt_number']);
            $table->unique(['seller_order_id', 'attempt_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pickup_attempts');
    }
};
