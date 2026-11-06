<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable audit trail for admin access to private identity documents.
     *
     * Only references are stored — never the document bytes, storage path, or
     * any token. Rows are append-only (see App\Models\Admin\DocumentAccessLog).
     */
    public function up(): void
    {
        Schema::create('admin_document_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('document_type', 20);
            $table->unsignedBigInteger('document_id');
            $table->string('document_key', 30);
            $table->string('action', 20);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_type', 'document_id', 'created_at'], 'admin_document_access_lookup_idx');
            $table->index(['admin_id', 'created_at'], 'admin_document_access_admin_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_document_access_logs');
    }
};
