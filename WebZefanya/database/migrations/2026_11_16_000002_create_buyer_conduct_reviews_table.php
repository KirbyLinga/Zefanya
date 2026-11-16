<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin conduct review cases for Buyers. A case is an alert for human review
 * only: nothing in this table restricts, warns or notifies a Buyer by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_conduct_reviews', function (Blueprint $table): void {
            $table->id();
            // Historical actor ID; do not block the existing account-deletion workflow.
            $table->unsignedBigInteger('buyer_id')->index();
            // cancellations | returns | seller_report
            $table->string('category', 20);
            // One review per Seller report. NULL for activity alerts.
            $table->foreignId('buyer_conduct_report_id')->nullable()->unique()
                ->constrained('buyer_conduct_reports')->restrictOnDelete();
            // First and last qualifying activity covered by this case.
            $table->timestamp('window_started_at')->nullable();
            $table->timestamp('window_ended_at')->nullable();
            $table->unsignedInteger('qualifying_count')->default(0);
            // Qualifying record ids (status history or return ids) at the time of the last scan.
            $table->json('evidence')->nullable();
            // pending | in_review | resolved
            $table->string('status', 20)->default('pending')->index();
            // 1 while an activity alert is unresolved: one open alert per Buyer and category.
            $table->unsignedTinyInteger('unresolved_key')->nullable();
            $table->unique(['buyer_id', 'category', 'unresolved_key'], 'buyer_conduct_review_open_unique');
            $table->timestamp('review_started_at')->nullable();
            $table->unsignedBigInteger('review_started_by')->nullable();
            // no_action | advisory | formal_warning | account_restriction | closed_duplicate | closed_unsubstantiated
            $table->string('decision', 30)->nullable();
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('account_status_before', 20)->nullable();
            $table->string('account_status_after', 20)->nullable();
            // Set once each notification is sent; a retry never sends it again.
            $table->timestamp('buyer_notified_at')->nullable();
            $table->timestamp('seller_review_notified_at')->nullable();
            $table->timestamp('seller_resolved_notified_at')->nullable();
            $table->timestamps();
            $table->index(['buyer_id', 'category', 'status']);
        });
    }

    public function down(): void
    {
        // Review decisions are part of the account audit trail. Roll back application files, not case records.
        throw new LogicException('Buyer conduct review history must not be dropped.');
    }
};
