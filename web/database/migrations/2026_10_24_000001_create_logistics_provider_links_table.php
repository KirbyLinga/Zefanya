<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Directed provider-to-provider delivery agreements.
 *
 * A link only becomes ACTIVE after both providers consent AND an Admin
 * approves it. Rows are never deleted; INACTIVE preserves the audit trail and
 * frees the directed pair for a new agreement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_provider_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('origin_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->foreignId('destination_provider_id')->constrained('logistics_providers')->restrictOnDelete();
            $table->unsignedInteger('priority')->default(0);

            // VARCHAR status (project convention — values live in the model).
            $table->string('status', 20)->default('PENDING');
            foreach (['origin', 'destination'] as $side) {
                $table->foreignId($side.'_consented_by')->nullable()->constrained('logistics_providers')->restrictOnDelete();
                $table->timestamp($side.'_consented_at')->nullable();
            }
            $table->foreignId('approved_by')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            // A generated nullable key provides MySQL-compatible partial uniqueness.
            $table->unsignedTinyInteger('active_slot')->nullable()
                ->storedAs("case when status = 'ACTIVE' then 1 else null end");
            $table->unique(['origin_provider_id', 'destination_provider_id', 'active_slot'], 'provider_links_active_pair_unique');
            $table->unique(['id', 'origin_provider_id', 'destination_provider_id'], 'provider_links_route_unique');
            $table->index(['origin_provider_id', 'status', 'priority'], 'provider_links_outgoing_idx');
            $table->index(['destination_provider_id', 'status'], 'provider_links_incoming_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_provider_links');
    }
};
