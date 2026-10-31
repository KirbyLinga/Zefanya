<?php

namespace App\Services\Shared;

use App\Enums\OrderStatus;
use App\Enums\PromotionKind;
use App\Models\Buyer\CartItem;
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\StoreSale;
use App\Models\Shared\OrderItem;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Cart and checkout line totals. A flash sale with a quantity covers that many
 * units at the sale price; the rest of the line stays at the regular price.
 * Bundle percentage and Buy X Get Y apply to the same portions. A unit already
 * on a store sale keeps that sale price.
 */
final class OfferPricing
{
    /** @var array<int, list<array{quantity: int, price: int, regular: int, sale_id: int|null}>> */
    private array $cache = [];

    /** @param Collection<int, CartItem> $items */
    public function bind(Collection $items): void
    {
        $this->cache = $this->build($items);
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** @return list<array{quantity: int, price: int, regular: int, sale_id: int|null}> */
    public function portions(CartItem $item): array
    {
        if (isset($this->cache[$item->id])) {
            return $this->cache[$item->id];
        }

        $this->cache = $this->build($this->buyerCart($item));

        return $this->cache[$item->id];
    }

    public function lineTotal(CartItem $item): int
    {
        $total = 0;

        foreach ($this->portions($item) as $portion) {
            $total += (int) bcmul((string) $portion['price'], (string) $portion['quantity'], 0);
        }

        return $total;
    }

    public function slotsLeft(StoreSale $sale): int
    {
        if ($sale->flash_quantity === null) {
            return PHP_INT_MAX;
        }

        $sold = (int) OrderItem::query()
            ->where('store_sale_id', $sale->id)
            ->whereHas('sellerOrder', fn ($query) => $query->where('status', '!=', OrderStatus::Cancelled->value))
            ->sum('quantity');

        return max(0, $sale->flash_quantity - $sold);
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @return array<int, list<array{quantity: int, price: int, regular: int, sale_id: int|null}>>
     */
    private function build(Collection $items): array
    {
        $result = [];

        foreach ($items->groupBy(fn (CartItem $item): int => (int) ($item->product?->seller_id ?? 0)) as $sellerId => $lines) {
            $promotions = $sellerId > 0
                ? SellerPromotion::query()->ongoing()->where('seller_id', $sellerId)->with('products:id')->get()
                : collect();
            $productIds = $lines->pluck('product_id')->unique();
            $bundle = $promotions
                ->filter(function (SellerPromotion $promotion) use ($productIds): bool {
                    $members = $promotion->products->pluck('id');

                    return $promotion->kind === PromotionKind::BundlePercent
                        && $members->count() >= 2
                        && $members->diff($productIds)->isEmpty();
                })
                ->sortByDesc('percent')
                ->first();
            $bxgy = $promotions
                ->filter(fn (SellerPromotion $promotion): bool => $promotion->kind === PromotionKind::BuyXGetY)
                ->keyBy(fn (SellerPromotion $promotion): int => (int) $promotion->products->first()?->id);

            $claimed = [];

            foreach ($lines->sortBy('id') as $line) {
                $result[$line->id] = $this->linePortions($line, $claimed, $bxgy->get($line->product_id), $bundle);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $claimed
     * @return list<array{quantity: int, price: int, regular: int, sale_id: int|null}>
     */
    private function linePortions(CartItem $item, array &$claimed, ?SellerPromotion $bxgy, ?SellerPromotion $bundle): array
    {
        $quantity = (int) $item->quantity;
        $resolved = $item->resolvedPrice();
        $regular = $resolved?->regularUnitCentavos() ?? 0;
        $sale = $item->product?->ongoingSale;
        $free = $this->freeUnits($bxgy, $quantity);
        $paid = $quantity - $free;
        $flash = 0;

        if ($resolved?->saleId !== null && $sale !== null && $paid > 0) {
            if ($sale->flash_quantity === null) {
                $flash = $paid;
            } else {
                $available = $this->claim($sale, $claimed);
                $flash = min($paid, $available);
                $claimed[$sale->id] = $available - $flash;
            }
        }

        $portions = [];
        $this->push($portions, $flash, $resolved?->unitCentavos() ?? $regular, $regular, $sale?->id);
        $regularPaid = $paid - $flash;
        $regularPrice = $bundle === null
            ? $regular
            : max(0, $regular - Money::percentage($regular, (int) $bundle->percent));
        $this->push($portions, $regularPaid, $regularPrice, $regular, null);
        $this->push($portions, $free, 0, 0, null);

        if ($portions === []) {
            $this->push($portions, $quantity, $regular, $regular, null);
        }

        return $portions;
    }

    /** @param array<int, int> $claimed */
    private function claim(StoreSale $sale, array &$claimed): int
    {
        if (! array_key_exists($sale->id, $claimed)) {
            $claimed[$sale->id] = $this->slotsLeft($sale);
        }

        return $claimed[$sale->id];
    }

    private function freeUnits(?SellerPromotion $deal, int $quantity): int
    {
        if ($deal === null || $deal->buy_quantity < 1 || $deal->get_quantity < 1) {
            return 0;
        }

        return intdiv($quantity, $deal->buy_quantity + $deal->get_quantity) * $deal->get_quantity;
    }

    /** @param list<array{quantity: int, price: int, regular: int, sale_id: int|null}> $portions */
    private function push(array &$portions, int $quantity, int $price, int $regular, ?int $saleId): void
    {
        if ($quantity < 1) {
            return;
        }

        $portions[] = [
            'quantity' => $quantity,
            'price' => $price,
            'regular' => $regular,
            'sale_id' => $saleId,
        ];
    }

    /** @return Collection<int, CartItem> */
    private function buyerCart(CartItem $item): Collection
    {
        $items = CartItem::query()
            ->where('buyer_id', $item->buyer_id)
            ->with(['product.ongoingSale', 'variant'])
            ->orderBy('id')
            ->get();

        if ($items->doesntContain('id', $item->id)) {
            $items->push($item);
        }

        return $items;
    }
}
