<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * vouchers - Discount codes from the platform or a seller.
 *
 * Belongs to:
 *   - sellers (seller_id) - optional
 * Used by: seller_orders, voucher_claims, voucher_product
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('vouchers')) {
            return;
        }

        Schema::create('vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('scope', 20)->default('platform');
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->restrictOnDelete();
            $table->string('type');
            $table->unsignedBigInteger('value');
            $table->unsignedBigInteger('max_discount')->nullable();
            $table->unsignedBigInteger('min_spend')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->unsignedInteger('per_buyer_limit')->nullable();
            $table->boolean('is_claimable')->default(false);
            $table->boolean('applies_to_all_products')->default(true);
            $table->timestamps();
            $table->string('funding_source', 20)->nullable();

            $table->unique('code');
            $table->index(['scope', 'seller_id', 'is_active'], 'vouchers_scope_seller_active_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('vouchers');
    }
};
