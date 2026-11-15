<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parcel_provider_allocations')) {
            return;
        }

        Schema::create('parcel_provider_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('role', 24);
            $table->unsignedBigInteger('share_minor')->nullable();
            $table->string('status', 24)->default('pending_policy');
            $table->timestamps();
            $table->unique(['seller_order_id', 'logistics_provider_id'], 'parcel_provider_allocation_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Provider allocation history must be retained. Roll back the application independently.');
    }
};
