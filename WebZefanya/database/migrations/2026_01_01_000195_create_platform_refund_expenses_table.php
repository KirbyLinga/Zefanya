<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_refund_expenses - Refund costs the platform paid.
 *
 * Belongs to:
 *   - admins (admin_id)
 *   - parcel_returns (parcel_return_id)
 *   - payments (payment_id) - optional
 *   - parcel_return_events (refund_event_id) - one-to-one
 *   - platform_refund_expenses (reverses_id) [itself] - one-to-one - optional
 *   - seller_orders (seller_order_id)
 */
return new class extends Migration
{
    public function up(): void
    {
        // A database built by the old incremental migrations already has this table.
        if (Schema::hasTable('platform_refund_expenses')) {
            return;
        }

        Schema::create('platform_refund_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parcel_return_id')->constrained('parcel_returns')->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('refund_event_id')->constrained('parcel_return_events')->restrictOnDelete();
            $table->string('entry_type', 16);
            $table->foreignId('reverses_id')->nullable()->constrained('platform_refund_expenses')->restrictOnDelete();
            $table->unsignedInteger('amount_minor');
            $table->string('reference', 64);
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->text('justification');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['occurred_at', 'entry_type']);
            $table->unique('reference');
            $table->unique('refund_event_id');
            $table->unique('reverses_id');
        });
    }

    public function down(): void
    {
        // Never drop the tables of a database that came from the old migrations.
        if (DB::table('migrations')->where('migration', '2024_01_01_000000_create_admins_table')->exists()) {
            return;
        }

        Schema::dropIfExists('platform_refund_expenses');
    }
};
