<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stuck-return recovery (audit I-01).
 *
 * - Failed buyer collections: a counter, the last failure and the Admin escalation
 *   once the limit is reached. Each attempt is also a numbered return event.
 * - Closing a return: who closed it, when and why (buyer cancellation, Admin
 *   closure, loss or damage in the return network).
 * - Seller receipt disputes: the reported problem and its private photos.
 * - earnings_restored_at: the one-time restoration of held Seller earnings.
 * - parcel_loss_cases.parcel_return_id: a loss case raised during a return.
 *
 * Status values need no schema change: parcel_returns.status is VARCHAR(40).
 * Additive only; nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->unsignedTinyInteger('collection_failed_attempts')->default(0);
            $table->timestamp('collection_last_failed_at')->nullable();
            $table->timestamp('collection_escalated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by_type', 20)->nullable();
            $table->unsignedBigInteger('closed_by_id')->nullable();
            $table->string('closed_reason', 1000)->nullable();
            $table->string('receipt_dispute_issue', 30)->nullable();
            $table->string('receipt_dispute_note', 1000)->nullable();
            $table->json('receipt_dispute_photos')->nullable();
            $table->timestamp('receipt_disputed_at')->nullable();
            $table->timestamp('earnings_restored_at')->nullable();
        });

        Schema::table('parcel_loss_cases', function (Blueprint $table): void {
            $table->unsignedBigInteger('parcel_return_id')->nullable()->after('incident_id');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_loss_cases', fn (Blueprint $table) => $table->dropColumn('parcel_return_id'));
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->dropColumn([
                'collection_failed_attempts', 'collection_last_failed_at', 'collection_escalated_at',
                'closed_at', 'closed_by_type', 'closed_by_id', 'closed_reason',
                'receipt_dispute_issue', 'receipt_dispute_note', 'receipt_dispute_photos', 'receipt_disputed_at',
                'earnings_restored_at',
            ]);
        });
    }
};
