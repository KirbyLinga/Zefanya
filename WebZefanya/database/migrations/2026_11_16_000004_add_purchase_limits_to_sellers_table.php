<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional Seller purchase limits per Buyer (COD protection). Additive only:
 * every column is nullable or false by default, so existing Sellers keep
 * selling exactly as before until they turn limits on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table): void {
            if (! Schema::hasColumn('sellers', 'purchase_limits_enabled')) {
                $table->boolean('purchase_limits_enabled')->default(false);
            }
            // Product value per order per Buyer, in centavos, after merchandise discounts, excluding shipping.
            if (! Schema::hasColumn('sellers', 'max_order_minor')) {
                $table->unsignedBigInteger('max_order_minor')->nullable();
            }
            if (! Schema::hasColumn('sellers', 'max_quantity_per_product')) {
                $table->unsignedInteger('max_quantity_per_product')->nullable();
            }
            // Total product value of the Buyer's undelivered, uncollected COD orders at this shop.
            if (! Schema::hasColumn('sellers', 'max_cod_exposure_minor')) {
                $table->unsignedBigInteger('max_cod_exposure_minor')->nullable();
            }
            if (! Schema::hasColumn('sellers', 'limits_exempt_trusted_buyers')) {
                $table->boolean('limits_exempt_trusted_buyers')->default(false);
            }
        });
    }

    public function down(): void
    {
        // Settings only; dropping them silently removes Sellers' protection. Roll back application files instead.
        throw new LogicException('Seller purchase limit settings must not be dropped automatically.');
    }
};
