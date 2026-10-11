<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attribution columns for the append-only status ledger.
     *
     * - actor_type / actor_id: WHO ran the transition as a typed pair
     *   (seller|logistics|rider|buyer|system + the row id of the actor).
     * - source: WHERE the change came from (web | simulator | api | system).
     *
     * `changed_by` (free-text label) is kept working — it stays nullable and
     * the service keeps filling it, so old rows and old readers are unaffected.
     */
    public function up(): void
    {
        Schema::table('status_histories', function (Blueprint $table): void {
            $table->string('actor_type', 20)->nullable()->after('changed_by');
            $table->unsignedBigInteger('actor_id')->nullable()->after('actor_type');
            $table->string('source', 20)->nullable()->after('actor_id');

            $table->index(['seller_order_id', 'actor_type']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('status_histories', function (Blueprint $table): void {
            $table->dropIndex(['seller_order_id', 'actor_type']);
            $table->dropIndex(['source']);
            $table->dropColumn(['actor_type', 'actor_id', 'source']);
        });
    }
};
