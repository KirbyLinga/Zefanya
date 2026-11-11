<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controlled cancellation and verified recovery for cross-provider handoffs,
 * and one-time overdue alerts. Additive columns only.
 *
 * A handoff cancelled before dispatch never left the sending hub. One
 * cancelled after dispatch keeps origin custody and an open exception until a
 * verified recovery scan at the sending hub records where the parcel is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_handoffs', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('received_by_id');
            $table->string('cancelled_by_type', 20)->nullable()->after('cancelled_at');
            $table->unsignedBigInteger('cancelled_by_id')->nullable()->after('cancelled_by_type');
            $table->string('cancellation_reason', 1000)->nullable()->after('cancelled_by_id');
            $table->timestamp('recovered_at')->nullable()->after('cancellation_reason');
            $table->string('recovered_by_type', 20)->nullable()->after('recovered_at');
            $table->unsignedBigInteger('recovered_by_id')->nullable()->after('recovered_by_type');
            $table->timestamp('overdue_notified_at')->nullable()->after('recovered_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_handoffs', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'cancelled_by_type', 'cancelled_by_id', 'cancellation_reason',
                'recovered_at', 'recovered_by_type', 'recovered_by_id', 'overdue_notified_at']);
        });
    }
};
