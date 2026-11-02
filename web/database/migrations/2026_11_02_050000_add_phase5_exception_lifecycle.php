<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: operational exception lifecycle and route reroute audit.
 *
 * The existing incidents/incident_events tables remain the single exception
 * record. This migration only adds the review/resolution metadata required for
 * OPEN -> UNDER_REVIEW -> RESOLVED and keeps every transition auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->unsignedInteger('blocked_leg_index')->nullable()->after('status');
            $table->string('resolution', 40)->nullable()->after('blocked_leg_index');
            $table->json('resolution_context')->nullable()->after('resolution');
            $table->timestamp('reviewed_at')->nullable()->after('resolution_context');
            $table->string('reviewed_by_type', 32)->nullable()->after('reviewed_at');
            $table->unsignedBigInteger('reviewed_by_id')->nullable()->after('reviewed_by_type');
            $table->timestamp('resolved_at')->nullable()->after('reviewed_by_id');
            $table->string('resolved_by_type', 32)->nullable()->after('resolved_at');
            $table->unsignedBigInteger('resolved_by_id')->nullable()->after('resolved_by_type');

            $table->index(['status', 'type'], 'incidents_status_type_idx');
            $table->index(['incident_seller_order_id', 'resolution'], 'incidents_parcel_resolution_idx');
        });

        Schema::table('incident_events', function (Blueprint $table): void {
            // Multiple reviews/resolutions must be recorded for one incident.
            $table->dropUnique('incident_events_incident_id_event_unique');

            $table->string('actor_type', 32)->nullable()->after('detail');
            $table->unsignedBigInteger('actor_id')->nullable()->after('actor_type');
            $table->string('from_status', 24)->nullable()->after('actor_id');
            $table->string('to_status', 24)->nullable()->after('from_status');
            $table->json('metadata')->nullable()->after('to_status');
        });

        Schema::table('parcel_route_rereoutes', function (Blueprint $table): void {
            $table->json('previous_path')->nullable()->after('failed_connection');
            $table->json('new_path')->nullable()->after('previous_path');
            $table->json('completed_legs')->nullable()->after('new_path');
            $table->string('authorized_by_type', 32)->nullable()->after('completed_legs');
            $table->unsignedBigInteger('authorized_by_id')->nullable()->after('authorized_by_type');
            $table->unsignedBigInteger('incident_id')->nullable()->after('authorized_by_id');
            $table->timestamp('rerouted_at')->nullable()->after('incident_id');

            $table->foreign('incident_id')->references('id')->on('incidents')->nullOnDelete();
            $table->index(['seller_order_id', 'rerouted_at'], 'rereoutes_parcel_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_route_rereoutes', function (Blueprint $table): void {
            $table->dropForeign(['incident_id']);
            $table->dropIndex('rereoutes_parcel_time_idx');
            $table->dropColumn([
                'previous_path',
                'new_path',
                'completed_legs',
                'authorized_by_type',
                'authorized_by_id',
                'incident_id',
                'rerouted_at',
            ]);
        });

        Schema::table('incident_events', function (Blueprint $table): void {
            $table->dropColumn(['actor_type', 'actor_id', 'from_status', 'to_status', 'metadata']);
            $table->unique(['incident_id', 'event']);
        });

        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropIndex('incidents_status_type_idx');
            $table->dropIndex('incidents_parcel_resolution_idx');
            $table->dropColumn([
                'blocked_leg_index',
                'resolution',
                'resolution_context',
                'reviewed_at',
                'reviewed_by_type',
                'reviewed_by_id',
                'resolved_at',
                'resolved_by_type',
                'resolved_by_id',
            ]);
        });
    }
};
