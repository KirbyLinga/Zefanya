<?php

namespace App\Services\Seller;

use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Single entry point for SELLER-initiated stock changes.
 *
 * Variant stock is authoritative (products.stock_quantity is a rollup), so
 * every change is applied to a locked variant row and mirrored into the
 * append-only inventory_transactions ledger inside the same transaction.
 *
 * Checkout still writes its own ledger rows (reason = 'checkout'); this
 * service covers seller edits (opening balance + manual adjustments).
 */
class StockService
{
    public const REASON_SELLER_INITIAL = 'seller_initial';

    public const REASON_SELLER_ADJUSTMENT = 'seller_adjustment';

    /**
     * Set a variant's stock to an absolute quantity and log the movement.
     *
     * The delta is computed against the LOCKED current value, so an order that
     * landed since the seller opened the form is reflected honestly in the
     * ledger (before/after/delta) rather than silently overwritten.
     *
     * @return InventoryTransaction|null null when the quantity did not change
     */
    public function set(
        ProductVariant $variant,
        mixed $newQuantity,
        string $reason = self::REASON_SELLER_ADJUSTMENT,
        ?int $sellerOrderId = null,
    ): ?InventoryTransaction {
        if (in_array($reason, [self::REASON_SELLER_INITIAL, self::REASON_SELLER_ADJUSTMENT], true)) {
            $newQuantity = self::validateSellerQuantity($newQuantity);
        } else {
            Validator::make(['stock_quantity' => $newQuantity], ['stock_quantity' => ['required', 'integer', 'min:0']])->validate();
            $newQuantity = (int) $newQuantity;
        }

        return DB::transaction(function () use ($variant, $newQuantity, $reason, $sellerOrderId): ?InventoryTransaction {
            $locked = ProductVariant::query()
                ->whereKey($variant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $before = (int) $locked->stock_quantity;
            if ($reason === 'return' && $sellerOrderId !== null && InventoryTransaction::query()
                ->where('variant_id', $locked->id)->where('seller_order_id', $sellerOrderId)->where('reason', 'return')->exists()) {
                return null;
            }

            if ($before === $newQuantity) {
                return null;
            }

            $locked->stock_quantity = $newQuantity;
            $locked->save();

            // Keep the caller's instance in step with the database.
            $variant->forceFill(['stock_quantity' => $newQuantity])
                ->syncOriginalAttribute('stock_quantity');

            return InventoryTransaction::query()->create([
                'product_id' => $locked->product_id,
                'variant_id' => $locked->id,
                'seller_order_id' => $sellerOrderId,
                'quantity_before' => $before,
                'quantity_after' => $newQuantity,
                'delta' => $newQuantity - $before,
                'reason' => $reason,
            ]);
        });
    }

    public static function validateSellerQuantity(mixed $quantity): int
    {
        Validator::make(['stock_quantity' => $quantity], [
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:99999'],
        ])->validate();

        return (int) $quantity;
    }

    /**
     * Log the opening balance of a BRAND-NEW product whose variants were
     * created with stock but no ledger history (the auto Default variant is
     * created by the Product `created` hook). Idempotent: variants that
     * already have any ledger row are skipped.
     *
     * Call this only right after creating a product, never for old products.
     */
    public function recordOpeningBalances(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $product->variants()->orderBy('id')->lockForUpdate()->get()->each(function (ProductVariant $variant): void {
                $hasHistory = InventoryTransaction::query()
                    ->where('variant_id', $variant->id)
                    ->exists();

                if ($hasHistory) {
                    return;
                }
                $quantity = self::validateSellerQuantity($variant->getAttributes()['stock_quantity']);
                if ($quantity === 0) {
                    return;
                }

                InventoryTransaction::query()->create([
                    'product_id' => $variant->product_id,
                    'variant_id' => $variant->id,
                    'seller_order_id' => null,
                    'quantity_before' => 0,
                    'quantity_after' => $quantity,
                    'delta' => $quantity,
                    'reason' => self::REASON_SELLER_INITIAL,
                ]);
            });
        });
    }
}
