<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * product_option_values - The choices of an option, such as "Red" and "Blue".
 *
 * Belongs to:
 *   - product_options (product_option_id)
 * Used by: product_variant_option_values
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('product_option_values')) {
            return;
        }

        Schema::create('product_option_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_option_id')->constrained('product_options')->cascadeOnDelete();
            $table->string('value', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_option_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('product_option_values');
    }
};
