<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller ↔ Logistics provider opt-in for shipping.
 * A seller may enable multiple providers that serve their registered
 * province and pickup barangay; uniqueness is scoped per seller.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_shipping_providers')) {
            return;
        }

        Schema::create('seller_shipping_providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(
                ['seller_id', 'logistics_provider_id'],
                'seller_shipping_providers_seller_provider_uq'
            );
            $table->index(['seller_id', 'is_enabled'], 'seller_shipping_providers_seller_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_shipping_providers');
    }
};
