<?php

namespace App\Services\Shared;

use App\Enums\StoreSaleType;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\StoreSale;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The one place that decides what a buyer pays. The product page, catalog and
 * search cards, cart, checkout and order snapshot all read from here, so they
 * cannot disagree. Regular prices stay on product_variants, untouched; vouchers
 * apply later, to the resolved subtotal.
 *
 * Product::buyerStartingPriceQuery() repeats this math in SQL for sort and filter.
 */
final class PriceResolver
{
    /** One cart line or variant. A null variant is a legacy line priced from the product rollup. */
    public function forVariant(Product $product, ?ProductVariant $variant): ResolvedPrice
    {
        return $this->summarize(collect([$this->line($product, $variant)]));
    }

    /** Every purchasable variant of a listed product; null when nothing can be bought. */
    public function forProduct(Product $product): ?ResolvedPrice
    {
        if (! $product->isListedForSale()) {
            return null;
        }

        $variants = $product->relationLoaded('variants')
            ? $product->variants->where('is_available', true)->where('stock_quantity', '>', 0)
            : $product->purchasableVariants;

        if ($variants->isEmpty()) {
            return null;
        }

        return $this->summarize($variants->map(fn (ProductVariant $variant): array => $this->line($product, $variant)));
    }

    /** @return array{regular: int, final: int, percent_off: int, sale_id: int|null} */
    private function line(Product $product, ?ProductVariant $variant): array
    {
        $regular = (int) bcmul((string) ($variant?->price ?? $product->price ?? 0), '100', 0);
        $sale = $product->ongoingSale;

        if ($sale === null || ($sale->variant_id !== null && $sale->variant_id !== $variant?->id)) {
            return ['regular' => $regular, 'final' => $regular, 'percent_off' => 0, 'sale_id' => null];
        }

        if ($sale->flash_quantity !== null && app(OfferPricing::class)->slotsLeft($sale) < 1) {
            return ['regular' => $regular, 'final' => $regular, 'percent_off' => 0, 'sale_id' => null];
        }

        $final = $this->salePrice($regular, $sale);

        return [
            'regular' => $regular,
            'final' => $final,
            'percent_off' => $this->percentOff($regular, $final, $sale),
            'sale_id' => $final < $regular ? $sale->id : null,
        ];
    }

    private function salePrice(int $regular, StoreSale $sale): int
    {
        return $sale->type === StoreSaleType::Percent
            ? $regular - Money::percentage($regular, $sale->value)
            // The regular price can drop after the sale was saved; a sale never raises it.
            : min($sale->value, $regular);
    }

    /** Whole percent, rounded down for a fixed price so a badge never overstates the saving. */
    private function percentOff(int $regular, int $final, StoreSale $sale): int
    {
        if ($final >= $regular) {
            return 0;
        }

        return $sale->type === StoreSaleType::Percent
            ? $sale->value
            : intdiv(($regular - $final) * 100, $regular);
    }

    /** @param Collection<int, array{regular: int, final: int, percent_off: int, sale_id: int|null}> $lines */
    private function summarize(Collection $lines): ResolvedPrice
    {
        return new ResolvedPrice(
            (int) $lines->min('regular'),
            (int) $lines->max('regular'),
            (int) $lines->min('final'),
            (int) $lines->max('final'),
            (int) $lines->min('percent_off'),
            (int) $lines->max('percent_off'),
            $lines->pluck('sale_id')->filter()->first(),
        );
    }
}
