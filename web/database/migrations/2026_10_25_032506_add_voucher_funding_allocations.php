<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            // No default: existing vouchers must not acquire an assumed funder.
            $table->string('funding_source', 20)->nullable();
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->restrictOnDelete();
            $table->string('voucher_funding_source', 20)->nullable();
            // NULL means no recorded allocation; zero is an explicit checkout snapshot.
            $table->unsignedBigInteger('voucher_discount_minor')->nullable();
            // A fully Seller-funded discount may exceed earnings after commission.
            $table->bigInteger('seller_net_minor')->nullable()->change();
        });

        Schema::table('seller_settlements', function (Blueprint $table): void {
            $table->unsignedBigInteger('seller_funded_discount_minor')->nullable();
            $table->unsignedBigInteger('marketplace_funded_discount_minor')->nullable();
            $table->bigInteger('seller_payout_minor')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Resolve any negative net records before reverting to unsigned columns.
        Schema::table('seller_settlements', function (Blueprint $table): void {
            $table->dropColumn(['seller_funded_discount_minor', 'marketplace_funded_discount_minor']);
            $table->unsignedBigInteger('seller_payout_minor')->nullable()->change();
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voucher_id');
            $table->dropColumn(['voucher_funding_source', 'voucher_discount_minor']);
            $table->unsignedBigInteger('seller_net_minor')->nullable()->change();
        });

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropColumn('funding_source');
        });
    }
};
