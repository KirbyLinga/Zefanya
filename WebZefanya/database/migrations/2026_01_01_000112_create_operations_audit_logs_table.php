<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * operations_audit_logs - Staff audit trail for sensitive operations.
 *
 * Belongs to: nothing (a starting table).
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('operations_audit_logs')) {
            return;
        }

        Schema::create('operations_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->unsignedBigInteger('logistics_provider_id')->nullable();
            $table->string('action', 40);
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_label');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'id']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['logistics_provider_id', 'id']);
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('operations_audit_logs');
    }
};
