<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for manifest lifecycle events (dispatch, receive, cancel, delete).
     * Append-only record capturing actor, timestamp, IP, user agent, and verification method.
     * Audit logs survive manifest deletion for compliance — FK is NOT enforced.
     */
    public function up(): void
    {
        Schema::create('manifest_audit_logs', function (Blueprint $table) {
            $table->id();

            // manifest_id is NOT a foreign key — audit trail must survive manifest deletion
            $table->unsignedBigInteger('manifest_id');

            // Nullable item reference; set to null if item is deleted
            $table->unsignedBigInteger('manifest_item_id')->nullable();
            $table->foreign('manifest_item_id')
                ->references('id')
                ->on('manifest_items')
                ->nullOnDelete();

            // Action: 'dispatched', 'received', 'cancelled', 'deleted'
            $table->string('action', 20);

            // Actor details (text snapshot, not FK — to survive actor deletion).
            $table->string('actor_type', 20); // 'logistics'
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');

            // Request context: IP and user agent for audit trail.
            $table->string('ip_address', 45)->nullable(); // IPv6: up to 45 chars
            $table->text('user_agent')->nullable();

            // Verification method: 'manual', 'qr_scan'
            $table->string('verification_method', 20)->default('manual');

            // Append-only: no updated_at, no soft deletes.
            $table->timestamp('created_at')->nullable();

            // Indexes for querying.
            $table->index(['manifest_id']);
            $table->index(['manifest_item_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manifest_audit_logs');
    }
};
