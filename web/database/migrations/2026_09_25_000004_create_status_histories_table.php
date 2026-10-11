<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only status change ledger for seller_orders.
     * Records every transition so the buyer/seller can see a timeline and
     * so the pipeline can be audited without a separate event stream.
     */
    public function up(): void
    {
        Schema::create('status_histories', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('seller_order_id')
                ->constrained('seller_orders')
                ->restrictOnDelete();

            // App\Enums\OrderStatus values (VARCHAR(20), mirrors the enum).
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);

            // Lightweight actor descriptor, e.g. "seller", "system", "courier",
            // "admin" — no polymorphic FK needed for an MVP audit log.
            $table->string('changed_by', 30)->nullable();

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['seller_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_histories');
    }
};
