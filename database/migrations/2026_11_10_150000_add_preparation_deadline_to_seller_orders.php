<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Seller's preparation window starts when the order is accepted. The deadline
 * is a promise to the buyer; missing it notifies the Seller once, but never
 * cancels the order or moves stock/money on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->timestamp('preparation_due_at')->nullable()->after('status');
            $table->timestamp('preparation_overdue_notified_at')->nullable()->after('preparation_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropColumn(['preparation_due_at', 'preparation_overdue_notified_at']);
        });
    }
};
