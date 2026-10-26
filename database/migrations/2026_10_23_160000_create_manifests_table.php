<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hub-to-hub transfer manifests.
 *
 * A manifest always belongs to one Logistics provider. The composite foreign
 * keys reference logistics_hubs' (id, logistics_provider_id) unique key, so the
 * database itself rejects an origin/destination hub owned by another provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manifests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('logistics_provider_id')
                ->constrained('logistics_providers')
                ->restrictOnDelete();

            $table->unsignedBigInteger('origin_hub_id');
            $table->unsignedBigInteger('destination_hub_id');

            // App\Enums\ManifestStatus values (VARCHAR, mirrors the enum).
            $table->string('status', 20)->default('DRAFT');

            $table->timestamp('dispatched_at')->nullable();
            $table->string('dispatched_by_type', 20)->nullable();
            $table->unsignedBigInteger('dispatched_by_id')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->string('received_by_type', 20)->nullable();
            $table->unsignedBigInteger('received_by_id')->nullable();

            $table->timestamps();

            $table->foreign(['origin_hub_id', 'logistics_provider_id'], 'manifests_origin_provider_fk')
                ->references(['id', 'logistics_provider_id'])
                ->on('logistics_hubs')
                ->restrictOnDelete();

            $table->foreign(['destination_hub_id', 'logistics_provider_id'], 'manifests_destination_provider_fk')
                ->references(['id', 'logistics_provider_id'])
                ->on('logistics_hubs')
                ->restrictOnDelete();

            $table->index(['logistics_provider_id', 'status'], 'manifests_provider_status_idx');
            $table->index(['origin_hub_id', 'destination_hub_id'], 'manifests_route_idx');
            $table->index(['destination_hub_id', 'status'], 'manifests_incoming_status_idx');
            $table->index(['status', 'dispatched_at'], 'manifests_status_dispatched_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifests');
    }
};
