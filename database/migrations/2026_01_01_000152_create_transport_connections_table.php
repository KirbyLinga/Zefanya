<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * transport_connections - Allowed hub-to-hub routes by ROAD, SEA or AIR.
 *
 * Belongs to:
 *   - logistics_hubs (destination_hub_id)
 *   - logistics_providers (operating_provider_id) - optional
 *   - logistics_hubs (origin_hub_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('transport_connections')) {
            return;
        }

        Schema::create('transport_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('origin_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->foreignId('destination_hub_id')->constrained('logistics_hubs')->restrictOnDelete();
            $table->enum('transport_mode', ['ROAD', 'SEA', 'AIR'])->default('ROAD');
            $table->foreignId('operating_provider_id')->nullable()->constrained('logistics_providers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['origin_hub_id', 'destination_hub_id', 'is_active'], 'transport_connection_lookup');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('transport_connections');
    }
};
