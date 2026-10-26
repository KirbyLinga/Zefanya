<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * incidents: one row per reported lost/damaged parcel.
     *
     * Status is a VARCHAR stored via a PHP backed enum in the model. It must
     * NEVER be mass-assigned from the request; only the incident service writes
     * it through controlled transitions.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();

            // The parcel model is SellerOrder (no dedicated Parcel model), so the
            // owner of the delivery references the seller order directly.
            $table->unsignedBigInteger('incident_seller_order_id');

            // Who reported the incident. READ-ONLY after creation: built from the
            // custody/assignment snapshot captured at report time, never request data.
            $table->unsignedBigInteger('reporter_id')->nullable();
            $table->string('reporter_type', 32);

            // Short, stable label. UI derives longer labels from this value.
            $table->string('type', 24);
            $table->string('status', 24)->default('open');

            // Free-text description the reporter left at report time.
            $table->text('description')->nullable();

            // UTC timestamp when the incident was created.
            $table->timestamp('happened_at')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('incident_seller_order_id')
                ->references('id')->on('seller_orders')
                ->onDelete('cascade');

            $table->index(['incident_seller_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
