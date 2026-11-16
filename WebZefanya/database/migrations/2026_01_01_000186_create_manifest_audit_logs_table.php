<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * manifest_audit_logs - Audit trail of manifest actions.
 *
 * Belongs to:
 *   - manifest_items (manifest_item_id) - optional
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('manifest_audit_logs')) {
            return;
        }

        Schema::create('manifest_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('manifest_id');
            $table->foreignId('manifest_item_id')->nullable()->constrained('manifest_items')->nullOnDelete();
            $table->string('action', 20);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('verification_method', 20)->default('manual');
            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index('manifest_id');
            $table->index('manifest_item_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('manifest_audit_logs');
    }
};
