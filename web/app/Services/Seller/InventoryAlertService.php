<?php

namespace App\Services\Seller;

use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Notifications\Seller\LowStockAlert;

class InventoryAlertService
{
    /**
     * Emit one low-stock notification when this movement crosses the product's
     * threshold for the affected VARIANT.
     *
     * The movement's quantity_before/quantity_after were written under the
     * variant row lock, so they are the authoritative pair for the crossing.
     * Evaluating them (instead of re-summing sibling variants) makes the check
     * idempotent under concurrency: the movement that first reaches the
     * threshold sees before > threshold, and every later movement on that
     * variant sees before <= threshold, so it cannot duplicate the alert.
     * Tracking is therefore independent per product and per variant.
     */
    public function record(InventoryTransaction $movement): void
    {
        if ($movement->delta >= 0) {
            return;
        }

        $product = Product::query()->with('seller')->find($movement->product_id);
        if ($product === null || $product->seller === null) {
            return;
        }

        $before = (int) $movement->quantity_before;
        $after = (int) $movement->quantity_after;

        if ($before <= $product->low_stock_threshold || $after > $product->low_stock_threshold) {
            return;
        }

        $variant = $movement->variant_id === null
            ? null
            : ProductVariant::query()->with('optionValues')->find($movement->variant_id);

        // A database notification is committed or rolled back with the stock ledger.
        $product->seller->notify(new LowStockAlert($product, $after, $movement->id, $variant));
    }
}
