<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * area_courier - Join table: which areas each rider serves.
 *
 * Belongs to:
 *   - areas (area_id)
 *   - couriers (courier_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('area_courier')) {
            return;
        }

        Schema::create('area_courier', function (Blueprint $table): void {
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained('couriers')->cascadeOnDelete();

            $table->primary(['area_id', 'courier_id']);
            $table->index('courier_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('area_courier');
    }
};
