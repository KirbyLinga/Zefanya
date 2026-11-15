<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * logistics_shipping_rates - A company's shipping price per zone.
 *
 * Belongs to:
 *   - logistics_providers (logistics_provider_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('logistics_shipping_rates')) {
            return;
        }

        Schema::create('logistics_shipping_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->cascadeOnDelete();
            $table->string('zone', 30);
            $table->unsignedInteger('first_kg_minor')->nullable();
            $table->unsignedInteger('additional_kg_minor')->nullable();
            $table->timestamps();

            $table->unique(['logistics_provider_id', 'zone']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('logistics_shipping_rates');
    }
};
