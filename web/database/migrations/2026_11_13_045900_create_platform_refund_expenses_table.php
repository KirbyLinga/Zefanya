<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 1.13: Zefanya's own cost for an exception refund it chose to fund. One expense per
 * recorded refund event and one reversal per voided expense, enforced by unique keys.
 * Additive only: no existing row is changed or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_refund_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parcel_return_id')->constrained('parcel_returns')->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            // The refund_recorded* event for an expense, or the refund_voided* event for a reversal.
            $table->foreignId('refund_event_id')->unique()->constrained('parcel_return_events')->restrictOnDelete();
            $table->string('entry_type', 16);
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('platform_refund_expenses')->restrictOnDelete();
            $table->unsignedInteger('amount_minor');
            $table->string('reference', 64)->unique();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->text('justification');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['occurred_at', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_refund_expenses');
    }
};
