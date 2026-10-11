<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time tokens guarding buyer checkout against double-submits.
     *
     * CheckoutController@index parks a random token in the session AND in this
     * table; placeOrder() claims (consumes) the row INSIDE the order
     * transaction, so a double-clicked second request finds it already used
     * and is bounced to the cart — no second order, no double stock move.
     *
     * Rows are ephemeral (one per checkout page render); stale ones are
     * garbage-collected opportunistically on insert.
     */
    public function up(): void
    {
        Schema::create('checkout_tokens', function (Blueprint $table): void {
            $table->id();

            // The token itself is the lookup key (128 bits of randomness).
            $table->string('token', 64)->unique();

            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();

            // NULL until consumed by a successful order; '1' can never be
            // claimed twice because the claiming UPDATE only matches used=0.
            $table->boolean('used')->default(false);

            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index(['buyer_id', 'used']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_tokens');
    }
};
