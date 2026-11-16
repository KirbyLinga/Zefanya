<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\UpdateProductPurchaseLimitsRequest;
use App\Models\Catalog\Product;
use App\Services\Buyer\PurchaseLimitService;
use App\Services\Seller\ProductPurchaseLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ProductPurchaseLimitController extends Controller
{
    public function update(UpdateProductPurchaseLimitsRequest $request, Product $product, ProductPurchaseLimitService $limits): JsonResponse|RedirectResponse
    {
        $product = $limits->set($request->user('seller'), $product, $request->limits());

        $active = PurchaseLimitService::limitsActive($product);
        $message = $active
            ? "Purchase limits saved for \"{$product->name}\". They apply to new checkouts; orders already placed are unchanged."
            : "Purchase limits are off for \"{$product->name}\".";

        if ($request->wantsJson()) {
            return response()->json(['id' => $product->id, 'active' => $active, 'limits' => self::payload($product), 'message' => $message]);
        }

        return to_route('seller.products.index')->with('success', $message);
    }

    /** The values the products page hands to the limits dialog (pesos as plain strings, empty for no limit). */
    public static function payload(Product $product): array
    {
        $pesos = fn (?int $minor): string => $minor === null ? '' : rtrim(rtrim(number_format($minor / 100, 2, '.', ''), '0'), '.');

        return [
            'enabled' => (bool) $product->purchase_limit_enabled,
            'max_quantity' => $product->purchase_limit_max_quantity === null ? '' : (string) $product->purchase_limit_max_quantity,
            'max_order' => $pesos($product->purchase_limit_max_order_minor),
            'max_active' => $pesos($product->purchase_limit_max_active_minor),
            'exempt_trusted' => (bool) $product->purchase_limit_exempt_trusted,
        ];
    }
}
