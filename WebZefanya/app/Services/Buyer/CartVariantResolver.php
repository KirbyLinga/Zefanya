<?php

namespace App\Services\Buyer;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use Illuminate\Validation\ValidationException;

class CartVariantResolver
{
    public function defaultVariant(Product $product): ?ProductVariant
    {
        $product->loadMissing('variants.optionValues');

        return $product->variants->count() === 1 && ! $product->variants->first()->hasOptions()
            ? $product->variants->first() : null;
    }

    public function resolve(Product $product, ?int $variantId): ProductVariant
    {
        if (! $product->isListedForSale()) {
            throw ValidationException::withMessages(['product_id' => 'This product is not available.']);
        }
        if ($product->isOnVacation()) {
            throw ValidationException::withMessages(['product_id' => Seller::VACATION_MESSAGE]);
        }

        $product->loadMissing('variants.optionValues');
        $requiresChoice = $this->defaultVariant($product) === null;
        if ($requiresChoice && $variantId === null) {
            throw ValidationException::withMessages(['variant_id' => 'Choose a color or size on the product page first.']);
        }
        $variant = $variantId !== null
            ? $product->variants->firstWhere('id', $variantId)
            : $product->variants->first();
        if (! $variant || ! $variant->is_available) {
            throw ValidationException::withMessages(['variant_id' => 'This product option is unavailable. Please choose another.']);
        }
        if ($variant->stock_quantity < 1) {
            throw ValidationException::withMessages(['variant_id' => 'This product option is out of stock.']);
        }

        return $variant;
    }
}
