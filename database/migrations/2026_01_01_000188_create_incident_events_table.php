<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * incident_events - The steps taken on an incident.
 *
 * Belongs to:
 *   - incidents (incident_id)
 *   - admins (resolved_by_admin_id) - optional
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('incident_events')) {
            return;
        }

        Schema::create('incident_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('event', 24)->comment('reported | rejected | resolved | resolved_by_admin');
            $table->string('label', 100)->nullable();
            $table->string('detail', 191)->nullable();
            $table->string('actor_type', 32)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->text('resolution_reason')->nullable();

            $table->index('incident_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('incident_events');
    }
};
