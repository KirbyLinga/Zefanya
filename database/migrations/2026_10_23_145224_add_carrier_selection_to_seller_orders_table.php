<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->foreignId('carrier_provider_id')->nullable()->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedInteger('shipping_fee_minor')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('carrier_provider_id');
            $table->dropColumn('shipping_fee_minor');
        });
    }
};
