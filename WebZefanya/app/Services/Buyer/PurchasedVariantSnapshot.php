<?php

namespace App\Services\Buyer;

use App\Models\Catalog\ProductOptionValue;
use App\Models\Catalog\ProductVariant;
use LogicException;

final class PurchasedVariantSnapshot
{
    /** @return list<array{name: string, value: string}> */
    public function capture(ProductVariant $variant): array
    {
        if (! $variant->relationLoaded('optionValues')) {
            throw new LogicException('Purchase options must come from the loaded locked variant.');
        }
        foreach ($variant->optionValues as $value) {
            if (! $value->relationLoaded('option') || $value->option === null) {
                throw new LogicException('Purchase option names must be loaded inside checkout.');
            }
        }

        return $variant->optionValues
            ->sortBy(fn (ProductOptionValue $value) => $value->option->sort_order)
            ->map(fn (ProductOptionValue $value): array => [
                'name' => $value->option->name,
                'value' => $value->value,
            ])->values()->all();
    }
}
