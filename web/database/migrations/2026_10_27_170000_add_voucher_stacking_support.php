<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            // Existing single-code vouchers keep working as platform vouchers.
            $table->string('scope', 20)->default('platform')->after('code');
            $table->foreignId('seller_id')->nullable()->after('scope')
                ->constrained('sellers')->restrictOnDelete();
            $table->index(['scope', 'seller_id', 'is_active'], 'vouchers_scope_seller_active_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // The legacy discount_minor remains the merchandise discount.
            $table->unsignedBigInteger('shop_discount_minor')->default(0)->after('discount_minor');
            $table->unsignedBigInteger('platform_discount_minor')->default(0)->after('shop_discount_minor');
            $table->unsignedBigInteger('shipping_discount_minor')->default(0)->after('platform_discount_minor');
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->foreignId('shop_voucher_id')->nullable()->after('voucher_discount_minor')
                ->constrained('vouchers')->restrictOnDelete();
            $table->string('shop_voucher_funding_source', 20)->nullable()->after('shop_voucher_id');
            $table->unsignedBigInteger('shop_voucher_discount_minor')->nullable()->after('shop_voucher_funding_source');

            $table->foreignId('platform_voucher_id')->nullable()->after('shop_voucher_discount_minor')
                ->constrained('vouchers')->restrictOnDelete();
            $table->string('platform_voucher_funding_source', 20)->nullable()->after('platform_voucher_id');
            $table->unsignedBigInteger('platform_voucher_discount_minor')->nullable()->after('platform_voucher_funding_source');

            $table->foreignId('shipping_voucher_id')->nullable()->after('platform_voucher_discount_minor')
                ->constrained('vouchers')->restrictOnDelete();
            $table->string('shipping_voucher_funding_source', 20)->nullable()->after('shipping_voucher_id');
            $table->unsignedBigInteger('shipping_voucher_discount_minor')->nullable()->after('shipping_voucher_funding_source');

            // Immutable checkout snapshots used by COD settlement. NULL on
            // historical rows means "not recorded"; zero is an explicit value.
            $table->unsignedBigInteger('shipping_discount_minor')->nullable()->after('shipping_voucher_discount_minor');
            $table->unsignedBigInteger('seller_funded_discount_minor')->nullable()->after('shipping_discount_minor');
            $table->unsignedBigInteger('marketplace_funded_discount_minor')->nullable()->after('seller_funded_discount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shop_voucher_id');
            $table->dropConstrainedForeignId('platform_voucher_id');
            $table->dropConstrainedForeignId('shipping_voucher_id');
            $table->dropColumn([
                'shop_voucher_funding_source',
                'shop_voucher_discount_minor',
                'platform_voucher_funding_source',
                'platform_voucher_discount_minor',
                'shipping_voucher_funding_source',
                'shipping_voucher_discount_minor',
                'shipping_discount_minor',
                'seller_funded_discount_minor',
                'marketplace_funded_discount_minor',
            ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['shop_discount_minor', 'platform_discount_minor', 'shipping_discount_minor']);
        });

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropIndex('vouchers_scope_seller_active_idx');
            $table->dropConstrainedForeignId('seller_id');
            $table->dropColumn('scope');
        });
    }
};
