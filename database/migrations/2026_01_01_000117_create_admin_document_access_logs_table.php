<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * admin_document_access_logs - Record of which admin viewed an uploaded ID or permit.
 *
 * Belongs to:
 *   - admins (admin_id) - optional
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('admin_document_access_logs')) {
            return;
        }

        Schema::create('admin_document_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('document_type', 20);
            $table->unsignedBigInteger('document_id');
            $table->string('document_key', 30);
            $table->string('action', 20);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_id', 'created_at'], 'admin_document_access_admin_idx');
            $table->index(['document_type', 'document_id', 'created_at'], 'admin_document_access_lookup_idx');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('admin_document_access_logs');
    }
};
