<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('courier_id')->constrained('couriers')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 30)->default('PENDING_REMITTANCE');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_remittances');
    }
};
