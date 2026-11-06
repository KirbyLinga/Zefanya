<?php

namespace App\Services\Buyer;

use App\Exceptions\CartItemUnavailableException;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Voucher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The buyer's cart: lines, quantity caps, removals and voucher slots.
 *
 * Moved verbatim from Buyer\CartController so the web cart and the Buyer
 * mobile API share one implementation. A cart is not a stock reservation:
 * nothing here writes stock or inventory transactions.
 */
class CartService
{
    /** The buyer's cart lines with every relation the summaries need. */
    public function items(Buyer $buyer): Collection
    {
        return $buyer
            ->cartItems()
            ->with([
                'product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category']),
                'variant.optionValues',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Add (merge) a line, capped at available stock.
     *
     * @return array{product: Product, variant: ProductVariant, quantity: int, quantity_limit: int, quantity_warning: ?string}
     *
     * @throws CartItemUnavailableException
     * @throws ValidationException out of stock
     */
    public function add(Buyer $buyer, int $productId, ?int $variantId, int $quantity): array
    {
        // `status = active` + the SoftDeletes default scope already excludes
        // drafts and deleted products.
        $product = Product::where('status', 'active')->whereHas('seller', fn ($query) => $query->where('status', 'approved'))
            ->find($productId);

        if (! $product || ! $product->isListedForSale()) {
            throw new CartItemUnavailableException('This product is not available.');
        }

        $variant = app(CartVariantResolver::class)->resolve($product, $variantId);
        $variantId = $variant->id;

        // Read-merge-write inside a transaction with a row lock. This protects
        // the unique(buyer_id, product_id, variant_id) constraint from
        // concurrent adds. Missing-row gap locks can deadlock on concurrent
        // first inserts, so the whole transaction is retried.
        $quantityResult = DB::transaction(function () use ($buyer, $product, $variantId, $quantity): array {
            $currentProduct = Product::query()->find($product->id);
            if ($currentProduct === null) {
                throw ValidationException::withMessages(['product_id' => 'This product is not available.']);
            }
            $currentProduct->setRelation('seller', Seller::query()
                ->whereKey($currentProduct->seller_id)->sharedLock()->first());
            $currentVariant = app(CartVariantResolver::class)->resolve($currentProduct, $variantId);
            $cap = $currentVariant->stock_quantity;

            $locked = CartItem::query()
                ->where('buyer_id', $buyer->id)
                ->where('product_id', $product->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $existing = $locked->firstWhere('variant_id', $variantId);

            if ($cap < 1) {
                throw ValidationException::withMessages(['quantity' => 'Sorry, this item is out of stock.']);
            }

            $wanted = ($existing?->quantity ?? 0) + $quantity;
            $newQty = min($wanted, $cap);

            if ($existing !== null) {
                $existing->update(['quantity' => $newQty]);
            } else {
                CartItem::create([
                    'buyer_id' => $buyer->id,
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'quantity' => $newQty,
                ]);
            }

            return [
                'quantity' => $newQty,
                'quantity_limit' => $cap,
                'quantity_warning' => $wanted > $cap ? "Only {$cap} available." : null,
            ];
        }, attempts: 3);

        return ['product' => $product, 'variant' => $variant, ...$quantityResult];
    }

    /**
     * Set a line's quantity, capped at available stock.
     *
     * @return array{item: CartItem, quantity_limit: int, quantity_warning: ?string}
     *
     * @throws CartItemUnavailableException
     * @throws ValidationException out of stock
     */
    public function updateQuantity(Buyer $buyer, int $itemId, int $quantity): array
    {
        $item = CartItem::where('id', $itemId)
            ->where('buyer_id', $buyer->id)
            ->firstOrFail();

        // The product may have been soft-deleted since it was added, in which
        // case $item->product is null and we must not touch the quantity.
        $product = Product::withTrashed()->find($item->product_id);

        if ($product === null) {
            throw new CartItemUnavailableException('This product no longer exists.');
        }

        if (! $item->isAvailable()) {
            throw new CartItemUnavailableException('This item is no longer available.');
        }

        $quantityResult = DB::transaction(function () use ($item, $quantity): array {
            $locked = CartItem::query()
                ->where('buyer_id', $item->buyer_id)
                ->where('product_id', $item->product_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $current = $locked->firstWhere('id', $item->id) ?? $item;
            $cap = $item->availableQuantity();

            if ($cap < 1) {
                throw ValidationException::withMessages(['quantity' => 'Sorry, this item is out of stock.']);
            }

            $current->update([
                'quantity' => min($quantity, $cap),
            ]);

            return [
                'quantity_limit' => $cap,
                'quantity_warning' => $quantity > $cap ? "Only {$cap} available." : null,
            ];
        });

        $item->refresh();

        return ['item' => $item, ...$quantityResult];
    }

    public function remove(Buyer $buyer, int $itemId): CartItem
    {
        $item = CartItem::where('id', $itemId)
            ->where('buyer_id', $buyer->id)
            ->firstOrFail();

        $item->delete();

        return $item;
    }

    /**
     * Remove several lines. Every id must belong to the buyer, otherwise the
     * whole request is rejected — never silently delete only a subset.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    public function removeMany(Buyer $buyer, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $ownedCount = CartItem::whereIn('id', $ids)->where('buyer_id', $buyer->id)->count();

        if ($ownedCount !== count($ids)) {
            abort(403, 'One or more cart items do not belong to you.');
        }

        CartItem::whereIn('id', $ids)->where('buyer_id', $buyer->id)->delete();

        return $ids;
    }

    /**
     * Summary of the (selected) lines with the stored vouchers re-validated.
     *
     * @param  array<int>|null  $selectedIds  null = every available line
     * @return array{summary: CartSummary, notice: ?string}
     */
    public function summary(Collection $items, VoucherSessionService $vouchers, ?array $selectedIds = null): array
    {
        $base = new CartSummary($items, null, $selectedIds);
        $notice = null;
        $application = $vouchers->resolve($base->sellerGroups, $base->subtotalCentavos, [], $notice);

        return ['summary' => new CartSummary($items, $application, $selectedIds), 'notice' => $notice];
    }

    /**
     * Apply a voucher code to its slot for the (selected) lines. Returns the
     * refusal reason, or null when applied.
     *
     * @param  array<int>|null  $selectedIds
     */
    public function applyVoucher(Buyer $buyer, VoucherSessionService $vouchers, Collection $items, string $code, ?string $requestedSlot, ?array $selectedIds = null): ?string
    {
        $voucher = Voucher::where('code', strtoupper(trim($code)))->first();

        if ($voucher === null) {
            return 'Voucher code not found.';
        }

        $base = new CartSummary($items, null, $selectedIds);
        $notice = null;

        if (! $vouchers->apply($voucher, $base->sellerGroups, $base->subtotalCentavos, $notice, $requestedSlot)) {
            return $notice;
        }

        // Using a listed voucher puts it in the buyer's wallet, so the rule lock
        // and the wallet both see every buyer holding it.
        if ($voucher->is_claimable) {
            $voucher->claims()->firstOrCreate(['buyer_id' => (int) $buyer->id]);
        }

        return null;
    }
}
