<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('reason');
            // Paths on the private disk; only the owning Seller can fetch them.
            $table->json('photos')->nullable()->after('description');
            // Set on buyer requests only: failed deliveries come back without a Seller decision.
            $table->timestamp('seller_response_due_at')->nullable()->after('requested_at');
            $table->string('seller_decision', 20)->nullable()->after('seller_response_due_at');
            $table->string('seller_decision_reason', 300)->nullable()->after('seller_decision');
            $table->timestamp('seller_decided_at')->nullable()->after('seller_decision_reason');
            $table->index(['seller_decision', 'seller_response_due_at'], 'parcel_returns_decision_due_idx');
        });

        // Open buyer requests get a full window from now: no Seller had this deadline before.
        DB::table('parcel_returns')
            ->where('status', 'RETURN_REQUESTED')
            ->whereIn('id', DB::table('parcel_return_events')->where('event_key', 'requested')->where('actor_type', 'buyer')->select('parcel_return_id'))
            ->update(['seller_response_due_at' => now()->addDays(3)]);
    }

    public function down(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->dropIndex('parcel_returns_decision_due_idx');
            $table->dropColumn(['description', 'photos', 'seller_response_due_at', 'seller_decision', 'seller_decision_reason', 'seller_decided_at']);
        });
    }
};
