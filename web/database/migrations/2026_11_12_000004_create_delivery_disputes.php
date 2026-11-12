<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained()->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 24);
            $table->text('explanation');
            $table->string('evidence_path')->nullable();
            $table->timestamp('delivered_at');
            $table->string('investigator')->nullable();
            $table->text('decision')->nullable();
            $table->string('outcome', 24)->nullable();
            $table->unsignedBigInteger('already_paid_minor')->default(0);
            $table->timestamp('financial_reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['logistics_provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_disputes');
    }
};
