<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H4: tie a review to the purchased line, record the verified badge, and
 * allow up to four uploaded photos.
 *
 * `order_item_id` is the identity the rest of the marketplace already uses for
 * "one thing that was bought": it survives the product being renamed or
 * removed, and it makes "one review per item" a database rule instead of an
 * application convention.
 *
 * The existing `reviews_buyer_order_product_unique` index is kept: one review
 * per (buyer, seller order, product) is still true, and order items for the
 * same product in the same seller order are not created by OrderPlacer. The
 * new unique index is narrower and would not replace it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('order_item_id')
                ->nullable()
                ->after('seller_order_id')
                ->constrained('order_items')
                ->restrictOnDelete();

            // Up to four server-stored photo paths. The old single
            // `photo_path` column stays for existing rows and old clients.
            $table->json('photo_paths')->nullable()->after('photo_path');

            // Server-computed, never request-supplied: true when the reviewed
            // line belongs to a delivered seller order.
            $table->boolean('verified_purchase')->default(false)->after('photo_paths');
        });

        // Backfill before adding the unique index. Dev data was empty, but a
        // populated installation must not fail the migration at boot.
        DB::table('reviews')
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->chunkById(100, function ($reviews): void {
                foreach ($reviews as $review) {
                    $itemId = DB::table('order_items')
                        ->where('seller_order_id', $review->seller_order_id)
                        ->where('product_id', $review->product_id)
                        ->orderBy('id')
                        ->value('id');

                    if ($itemId !== null) {
                        DB::table('reviews')
                            ->where('id', $review->id)
                            ->update(['order_item_id' => $itemId]);
                    }
                }
            });

        DB::table('reviews')
            ->whereNotNull('photo_path')
            ->whereNull('photo_paths')
            ->orderBy('id')
            ->chunkById(100, function ($reviews): void {
                foreach ($reviews as $review) {
                    DB::table('reviews')
                        ->where('id', $review->id)
                        ->update(['photo_paths' => json_encode([$review->photo_path])]);
                }
            });

        DB::table('reviews')
            ->whereIn('seller_order_id', function ($query): void {
                $query->select('id')
                    ->from('seller_orders')
                    ->where('status', 'DELIVERED');
            })
            ->update(['verified_purchase' => true]);

        Schema::table('reviews', function (Blueprint $table): void {
            $table->unique('order_item_id', 'reviews_order_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropUnique('reviews_order_item_unique');
            $table->dropConstrainedForeignId('order_item_id');
            $table->dropColumn(['photo_paths', 'verified_purchase']);
        });
    }
};
