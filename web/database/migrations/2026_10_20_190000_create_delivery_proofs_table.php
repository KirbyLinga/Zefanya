<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof of delivery submitted by the Rider at the buyer's door.
 *
 * One row per seller_order (the unique index makes a duplicate delivery
 * confirmation impossible at the storage layer, not just in the controller).
 * The evidence file is written to the private `local` disk; this table only
 * stores the path plus the audit details of who received the parcel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_proofs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('seller_order_id')->unique()
                ->constrained('seller_orders')->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()
                ->constrained('couriers')->nullOnDelete();
            $table->foreignId('rider_id')->nullable()
                ->constrained('riders')->nullOnDelete();

            $table->string('photo_path');
            $table->string('photo_mime', 100)->nullable();
            $table->unsignedBigInteger('photo_size')->nullable();

            // Who physically accepted the parcel (may differ from the buyer).
            $table->string('received_by')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_proofs');
    }
};
