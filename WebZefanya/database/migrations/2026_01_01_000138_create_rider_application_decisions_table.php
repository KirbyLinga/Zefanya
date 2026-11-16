<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rider_application_decisions - History of each approve, reject or resubmit decision on a rider application.
 *
 * Belongs to:
 *   - logistics_providers (logistics_provider_id)
 *   - rider_applications (rider_application_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('rider_application_decisions')) {
            return;
        }

        Schema::create('rider_application_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_application_id')->constrained('rider_applications')->restrictOnDelete();
            $table->foreignId('logistics_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('reason', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('rider_application_decisions');
    }
};
