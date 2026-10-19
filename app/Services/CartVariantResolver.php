<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
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

        $product->loadMissing('variants.optionValues');
        $requiresChoice = $this->defaultVariant($product) === null;
        if ($requiresChoice && $variantId === null) {
            throw ValidationException::withMessages(['variant_id' => 'Choose a color or size on the product page first.']);
        }
        $variant = $variantId !== null
            ? $product->variants->firstWhere('id', $variantId)
            : $product->variants->first();
        if (! $variant || ! $variant->is_available || $variant->stock_quantity < 1) {
            throw ValidationException::withMessages(['variant_id' => 'This product option is unavailable. Please choose another.']);
        }

        return $variant;
    }
}
