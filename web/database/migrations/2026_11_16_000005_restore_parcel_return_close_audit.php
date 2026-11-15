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
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by_type', 32)->nullable();
            $table->unsignedBigInteger('closed_by_id')->nullable();
            $table->text('closed_reason')->nullable();
            $table->timestamp('earnings_restored_at')->nullable();
            $table->timestamp('collection_retry_authorized_at')->nullable();
            $table->unsignedBigInteger('collection_retry_authorized_by_id')->nullable();
            $table->string('receipt_dispute_issue')->nullable();
            $table->text('receipt_dispute_note')->nullable();
            $table->json('receipt_dispute_photos')->nullable();
            $table->timestamp('receipt_disputed_at')->nullable();
            $table->string('disposition')->nullable();
            $table->text('disposition_note')->nullable();
            $table->timestamp('disposition_set_at')->nullable();
            $table->unsignedBigInteger('disposition_set_by_admin_id')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('parcel_returns')->whereNotNull('closed_at')->orWhereNotNull('earnings_restored_at')
            ->orWhereNotNull('collection_retry_authorized_at')->orWhereNotNull('receipt_disputed_at')
            ->orWhereNotNull('disposition_set_at')->exists()) {
            throw new RuntimeException('Cannot remove recorded return recovery evidence.');
        }

        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->dropColumn([
                'closed_at', 'closed_by_type', 'closed_by_id', 'closed_reason', 'earnings_restored_at',
                'collection_retry_authorized_at', 'collection_retry_authorized_by_id', 'receipt_dispute_issue',
                'receipt_dispute_note', 'receipt_dispute_photos', 'receipt_disputed_at', 'disposition',
                'disposition_note', 'disposition_set_at', 'disposition_set_by_admin_id',
            ]);
        });
    }
};
