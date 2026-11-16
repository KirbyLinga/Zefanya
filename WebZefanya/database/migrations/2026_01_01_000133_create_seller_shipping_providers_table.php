<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * seller_shipping_providers - Which delivery companies a seller has switched on.
 *
 * Belongs to:
 *   - logistics_providers (logistics_provider_id)
 *   - sellers (seller_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('seller_shipping_providers')) {
            return;
        }

        Schema::create('seller_shipping_providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index(['seller_id', 'is_enabled'], 'seller_shipping_providers_seller_enabled_idx');
            $table->unique(['seller_id', 'logistics_provider_id'], 'seller_shipping_providers_seller_provider_uq');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('seller_shipping_providers');
    }
};
