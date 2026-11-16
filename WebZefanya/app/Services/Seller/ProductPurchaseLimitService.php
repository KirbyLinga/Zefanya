<?php

namespace App\Services\Seller;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Services\Buyer\PurchaseLimitService;
use Illuminate\Support\Facades\DB;

/**
 * Saves one product's purchase limits under a lock on that product row. A
 * checkout locks the same product row before it reads the limits, so a
 * checkout sees either the old or the new limits, never a mix. Only this
 * product row is locked, so the checkout lock order is unaffected.
 */
class ProductPurchaseLimitService
{
    /**
     * @param  array{purchase_limit_enabled: bool, purchase_limit_max_quantity: ?int, purchase_limit_max_order_minor: ?int, purchase_limit_max_active_minor: ?int, purchase_limit_exempt_trusted: bool}  $limits
     */
    public function set(Seller $seller, Product $product, array $limits): Product
    {
        abort_unless(PurchaseLimitService::available(), 503, 'Purchase limits are awaiting a database update.');

        return DB::transaction(function () use ($seller, $product, $limits): Product {
            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->seller_id === (int) $seller->getKey() && $seller->isApproved(), 403);
            abort_if($locked->isRestricted(), 403, 'This product is restricted by an administrator and cannot be modified.');

            $locked->forceFill([
                'purchase_limit_enabled' => $limits['purchase_limit_enabled'],
                'purchase_limit_max_quantity' => $limits['purchase_limit_max_quantity'],
                'purchase_limit_max_order_minor' => $limits['purchase_limit_max_order_minor'],
                'purchase_limit_max_active_minor' => $limits['purchase_limit_max_active_minor'],
                'purchase_limit_exempt_trusted' => $limits['purchase_limit_exempt_trusted'],
            ])->save();

            return $locked;
        });
    }
}
