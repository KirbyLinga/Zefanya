<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            // Frozen at checkout so later catalog weight edits never change an
            // in-transit parcel, and a Rider's load can be summed in one query.
            $table->unsignedInteger('parcel_weight_grams')->nullable()->after('subtotal_minor');
        });

        // Backfill existing parcels from their current line items. New orders
        // are snapshotted by the checkout flow and the OrderItem model hook.
        DB::table('seller_orders')->select('id')->orderBy('id')->chunkById(200, function ($orders): void {
            foreach ($orders as $order) {
                $weight = (int) DB::table('order_items')
                    ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.variant_id')
                    ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
                    ->where('order_items.seller_order_id', $order->id)
                    ->selectRaw('COALESCE(SUM(order_items.quantity * COALESCE(product_variants.weight_grams, products.weight_grams, 0)), 0) AS weight')
                    ->value('weight');

                DB::table('seller_orders')
                    ->where('id', $order->id)
                    ->update(['parcel_weight_grams' => $weight]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropColumn('parcel_weight_grams');
        });
    }
};
