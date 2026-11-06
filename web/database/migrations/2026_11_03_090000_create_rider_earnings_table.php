<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_earnings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('courier_id')->constrained('couriers')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('job', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('earned_at');
            $table->timestamps();

            // One paid pickup and one paid delivery per parcel, however often it is retried.
            $table->unique(['seller_order_id', 'job']);
            $table->index(['courier_id', 'earned_at']);
            $table->index(['logistics_provider_id', 'earned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_earnings');
    }
};
