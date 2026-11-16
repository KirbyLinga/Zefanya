<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-product purchase limits per Buyer (COD protection). Additive
 * only: every column is nullable or false by default, so every product keeps
 * selling exactly as before until its Seller turns limits on for it.
 *
 * Replaces the shop-wide settings from 2026_11_16_000004 (those sellers
 * columns are left in place, unused, because dropping columns on the live
 * database needs separate approval).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'purchase_limit_enabled')) {
                $table->boolean('purchase_limit_enabled')->default(false);
            }
            // Units of this product per order.
            if (! Schema::hasColumn('products', 'purchase_limit_max_quantity')) {
                $table->unsignedInteger('purchase_limit_max_quantity')->nullable();
            }
            // Value of this product per order, in centavos, after merchandise discounts, excluding shipping.
            if (! Schema::hasColumn('products', 'purchase_limit_max_order_minor')) {
                $table->unsignedBigInteger('purchase_limit_max_order_minor')->nullable();
            }
            // Value of this product on the Buyer's open, uncollected COD orders plus the new order.
            if (! Schema::hasColumn('products', 'purchase_limit_max_active_minor')) {
                $table->unsignedBigInteger('purchase_limit_max_active_minor')->nullable();
            }
            if (! Schema::hasColumn('products', 'purchase_limit_exempt_trusted')) {
                $table->boolean('purchase_limit_exempt_trusted')->default(false);
            }
        });
    }

    public function down(): void
    {
        // Settings only; dropping them silently removes Sellers' protection. Roll back application files instead.
        throw new LogicException('Product purchase limit settings must not be dropped automatically.');
    }
};
