<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin phone confirmation of cross-region delivery addresses. A pending or
 * not-confirmed row holds its shipment before pickup; nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_address_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_order_id')->unique()->constrained('seller_orders')->restrictOnDelete();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('buyer_id')->index();
            $table->unsignedBigInteger('seller_id')->index();
            $table->string('seller_region', 4);
            $table->string('buyer_region', 4);
            $table->string('seller_province', 120)->nullable();
            $table->string('buyer_province', 120)->nullable();
            // Phone number on the order's shipping snapshot at checkout.
            $table->string('contact_no', 40)->nullable();
            // pending | confirmed | not_confirmed
            $table->string('status', 20)->default('pending')->index();
            // reached_confirmed | reached_incorrect | no_answer | wrong_number
            $table->string('call_outcome', 30)->nullable();
            $table->timestamp('called_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Address checks are part of the order audit trail. Roll back application files, not records.
        throw new LogicException('Order address review history must not be dropped.');
    }
};
