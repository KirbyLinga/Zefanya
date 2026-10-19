<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log of every courier assignment (pickup and delivery legs),
 * including re-assignments after a failed delivery. `courier_id` uses
 * nullOnDelete so the history survives a courier being removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained('couriers')->nullOnDelete();
            // pickup | delivery
            $table->string('role', 12);
            // Who performed the assignment (logistics | rider | system).
            $table->string('assigned_by_type', 20)->nullable();
            $table->unsignedBigInteger('assigned_by_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['seller_order_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_assignments');
    }
};
