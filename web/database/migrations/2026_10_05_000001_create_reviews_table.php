<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();

            // buyer_id → buyers.id: RESTRICT — a review belongs to a real account;
            // deleting a buyer while their reviews still exist is an admin decision
            // that should fail loudly rather than cascade silently.
            $table->foreignId('buyer_id')->constrained('buyers')->restrictOnDelete();

            // seller_order_id → seller_orders.id: RESTRICT — the review is for a
            // specific delivery (not the product in the abstract), so it is always
            // attached to the fulfilment unit that shipped it. A seller_order is
            // also a permanent record (its own FK is RESTRICT), so this will never
            // fire in practice.
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();

            // product_id → products.id: SET NULL — the review keeps its text and
            // rating even if the product row is later removed (soft-deleted in
            // practice). A null here means "reviewed a product that no longer exists",
            // not "reviewed nothing", so the review aggregate is still valid.
            //
            // This is the same pattern order_items uses for the same reason: the
            // purchase record survives the product's removal.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            // 1–5 only; enforced at the application layer too (see ReviewController),
            // but the column keeps the assertion server-side for direct writes.
            $table->unsignedTinyInteger('stars');

            // Empty string rather than null: "no comment" is a real state that
            // needs no special treatment in display code. The Dart model uses the
            // same convention. No default on the column itself — MySQL forbids
            // defaults on TEXT columns; the controller passes '' explicitly.
            $table->text('comment')->nullable();

            // A photo the buyer attached to the review, stored on the public disk.
            // Nullable: most reviews will have no photo.
            $table->string('photo_path')->nullable();

            $table->timestamps();

            // One review per buyer per product per order. This is the unique
            // identity the mobile app already relies on: forOrderProduct(orderId,
            // productId) returns at most one review, and re-submitting is an edit.
            // The constraint makes that a database rule, not just an app
            // convention.
            $table->unique(['buyer_id', 'seller_order_id', 'product_id'], 'reviews_buyer_order_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
