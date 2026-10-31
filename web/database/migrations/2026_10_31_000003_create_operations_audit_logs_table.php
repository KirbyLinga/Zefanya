<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only record of sensitive operations. There are deliberately no
     * foreign keys: a log row must outlive whatever it describes, so the actor
     * and entity names are stored as text at the time of the action.
     */
    public function up(): void
    {
        Schema::create('operations_audit_logs', function (Blueprint $table) {
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

            $table->index(['logistics_provider_id', 'id']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['action', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_audit_logs');
    }
};
