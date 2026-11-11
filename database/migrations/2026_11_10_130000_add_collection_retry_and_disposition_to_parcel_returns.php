<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stuck-return recovery (audit I-01), follow-up.
 *
 * - collection_retry_authorized_*: Admin explicitly authorizes one extra buyer
 *   collection after the normal limit. It is not granted automatically.
 * - disposition_*: what physically happened to a parcel whose Seller receipt was
 *   disputed (returned to Seller, destroyed, still held, returned to buyer...).
 *   Recording it never moves custody, refunds money or changes stock.
 *
 * Additive only; nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->timestamp('collection_retry_authorized_at')->nullable();
            $table->unsignedBigInteger('collection_retry_authorized_by_id')->nullable();
            $table->string('disposition', 30)->nullable();
            $table->string('disposition_note', 1000)->nullable();
            $table->timestamp('disposition_set_at')->nullable();
            $table->unsignedBigInteger('disposition_set_by_admin_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('parcel_returns', function (Blueprint $table): void {
            $table->dropColumn([
                'collection_retry_authorized_at', 'collection_retry_authorized_by_id',
                'disposition', 'disposition_note', 'disposition_set_at', 'disposition_set_by_admin_id',
            ]);
        });
    }
};
