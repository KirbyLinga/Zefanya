<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parcels included in a hub-to-hub manifest.
 *
 * Receipt is per item so a destination hub can receive a manifest partially.
 * `tracking_number` is a snapshot of seller_orders.tracking_number at the time
 * the parcel is manifested, preserving what was printed on the physical label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manifest_items', function (Blueprint $table): void {
            $table->id();

            // RESTRICT on both sides: historical manifests are an audit record
            // and must not disappear when a manifest or parcel is removed.
            $table->foreignId('manifest_id')
                ->constrained('manifests')
                ->restrictOnDelete();

            $table->foreignId('seller_order_id')
                ->constrained('seller_orders')
                ->restrictOnDelete();

            $table->string('tracking_number', 64)->nullable();

            $table->timestamp('received_at')->nullable();
            $table->string('received_by_type', 20)->nullable();
            $table->unsignedBigInteger('received_by_id')->nullable();

            // Set when the parcel leaves the active manifest (received or
            // explicitly released). Historical rows stay in place.
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            // MySQL has no partial unique index. This generated column equals
            // the parcel id while the item is active and NULL after release;
            // MySQL UNIQUE treats NULLs as distinct, so only one active
            // membership per parcel is possible while history remains intact.
            $table->unsignedBigInteger('active_seller_order_id')
                ->nullable()
                ->storedAs('case when released_at is null then seller_order_id else null end');

            $table->unique('active_seller_order_id', 'manifest_items_active_parcel_unique');
            $table->index(['manifest_id', 'received_at'], 'manifest_items_receipt_idx');
            $table->index('seller_order_id', 'manifest_items_seller_order_idx');
            $table->index('tracking_number', 'manifest_items_tracking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifest_items');
    }
};
