<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * incident_events: every lifecycle event on an incident, so admins can see
     * exactly when an incident was opened and each resolution attempt.
     */
    public function up(): void
    {
        Schema::create('incident_events', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('incident_id')->index();

            // Which lifecycle event happened. READ-ONLY after creation.
            $table->string('event', 24)->comment('reported | rejected | resolved | resolved_by_admin');

            // Human-readable label for this event.
            $table->string('label', 100)->nullable();

            // Internal detail (e.g. "REJECT" → next status transition).
            $table->string('detail', 191)->nullable();

            // UTC timestamp when the event happened.
            $table->timestamp('occurred_at');

            $table->foreign('incident_id')
                ->references('id')->on('incidents')
                ->onDelete('cascade');

            $table->unique(['incident_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_events');
    }
};
