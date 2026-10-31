<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            // Largest discount in centavos, for percent vouchers; null = no cap
            $table->unsignedBigInteger('max_discount')->nullable()->after('value');
            // Null = already started; existing vouchers keep working as they are
            $table->dateTime('starts_at')->nullable()->after('is_active');
            // Orders one buyer may place with this voucher; null = no per-buyer limit
            $table->unsignedInteger('per_buyer_limit')->nullable()->after('times_used');
            // Claimable vouchers may be offered to buyers; code-only ones are never advertised
            $table->boolean('is_claimable')->default(false)->after('per_buyer_limit');
            // False = only the products in voucher_product count toward the voucher
            $table->boolean('applies_to_all_products')->default(true)->after('is_claimable');
        });

        Schema::create('voucher_product', function (Blueprint $table): void {
            $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['voucher_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_product');

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropColumn(['max_discount', 'starts_at', 'per_buyer_limit', 'is_claimable', 'applies_to_all_products']);
        });
    }
};
