<?php

namespace App\Services\Buyer;

use App\Models\Buyer\CartItem;
use App\Models\Seller\Seller;
use App\Models\Shared\Voucher;
use App\Services\Shared\OfferPricing;
use Illuminate\Support\Collection;

/**
 * Computes cart totals in integer centavos to avoid float precision errors.
 *
 * Used by:
 *  - CartController@index   (full page render)
 *  - CartController@update  (PATCH — returns updated JSON totals)
 *  - CartController@summary (GET — lightweight JSON refresh after selection changes)
 *  - CheckoutController@index (to confirm totals before placing an order)
 *
 * Selection semantics (important):
 *   $selectedIds === null  → "no selection was sent"          → every available item counts
 *   $selectedIds === []    → "the buyer deselected everything" → totals are zero
 * Never conflate the two: it is what makes "Proceed to Checkout" disable correctly.
 */
final class CartSummary
{
    /** @var Collection<int, CartItem>  Items that are fully available */
    public readonly Collection $availableItems;

    /** @var Collection<int, CartItem>  Items that are unavailable (dimmed rows) */
    public readonly Collection $unavailableItems;

    /** @var Collection<int, array{seller: Seller, items: Collection}>  Grouped by seller */
    public readonly Collection $sellerGroups;

    public readonly int $subtotalCentavos;

    public readonly int $discountCentavos;

    public readonly int $shippingDiscountCentavos;

    public readonly int $totalCentavos;

    public readonly VoucherApplication $voucherApplication;

    public readonly ?Voucher $voucher;

    /** @var Collection<int, CartItem>  Every line in the cart, available or not */
    private readonly Collection $allItems;

    /** @var array<int>|null null = all available items, [] = nothing selected */
    private readonly ?array $selectedIds;

    public function __construct(
        /** @param Collection<int, CartItem> $allItems All cart items, already loaded with product.images + product.seller + product.category */
        Collection $allItems,
        Voucher|VoucherApplication|null $voucher = null,
        /** @param array<int>|null $selectedIds Cart item ids to include in the totals */
        ?array $selectedIds = null,
    ) {
        $this->allItems = $allItems;

        $this->availableItems = $allItems
            ->filter(fn (CartItem $i): bool => $i->isAvailable())
            ->values();

        $this->unavailableItems = $allItems
            ->reject(fn (CartItem $i): bool => $i->isAvailable())
            ->values();

        $this->selectedIds = $selectedIds;

        // Group available items by seller for the page layout
        // Filter by selectedIds so totals match displayed items
        $selectedAvailable = $this->selectedIds === null
            ? $this->availableItems
            : $this->availableItems->whereIn('id', $this->selectedIds)->values();

        $this->sellerGroups = $selectedAvailable
            ->groupBy(fn (CartItem $i): int => (int) $i->product->seller_id)
            ->map(fn (Collection $group): array => [
                'seller' => $group->first()->product->seller,
                'items' => $group->values(),
            ])
            ->values();

        // Totals computed over selected items only. Bind first so a bundle or
        // Buy X Get Y sees the lines being purchased, not the rest of the cart.
        app(OfferPricing::class)->bind($this->selectedItems());

        $this->subtotalCentavos = (int) $this->selectedItems()
            ->sum(fn (CartItem $i): int => $i->lineTotalCentavos());

        $this->voucherApplication = match (true) {
            $voucher instanceof VoucherApplication => $voucher,
            $voucher instanceof Voucher => new VoucherApplication(collect([$voucher]), $this->sellerGroups),
            default => VoucherApplication::empty($this->sellerGroups),
        };

        $this->discountCentavos = $this->voucherApplication->discountCentavos;
        $this->shippingDiscountCentavos = $this->voucherApplication->shippingDiscountCentavos;
        $this->voucher = $this->voucherApplication->platformVoucher
            ?? $this->voucherApplication->vouchers->first();

        $this->totalCentavos = max(0, $this->subtotalCentavos - $this->discountCentavos);
    }

    // ── Selection helpers ───────────────────────────────────────────────────

    /** @return Collection<int, CartItem> */
    private function selectedItems(): Collection
    {
        if ($this->selectedIds === null) {
            return $this->availableItems;
        }

        return $this->availableItems->whereIn('id', $this->selectedIds)->values();
    }

    /** Number of distinct SELECTED cart lines. */
    public function selectedLineCount(): int
    {
        return $this->selectedItems()->count();
    }

    /** Number of distinct lines the buyer could tick (drives "Select all (N items)"). */
    public function availableLineCount(): int
    {
        return $this->availableItems->count();
    }

    /**
     * Number of EVERY cart line, available or not — this is the navbar badge,
     * so the shopper still sees items that went out of stock.
     */
    public function cartLineCount(): int
    {
        return $this->allItems->count();
    }

    public function isEmpty(): bool
    {
        return $this->allItems->isEmpty();
    }

    /**
     * Identifies the unit prices of the selected lines. The checkout page sends
     * it back, so a price that moved while the buyer was reviewing (a sale
     * starting or ending) stops the order instead of charging a different total.
     */
    public function priceFingerprint(): string
    {
        $lines = $this->selectedItems()
            ->sortBy('id')
            ->map(fn (CartItem $i): string => $i->id.':'.$i->unitPriceCentavos())
            ->implode('|');

        return hash('sha256', $lines);
    }

    // ── Formatted helpers (₱X,XXX.XX) ───────────────────────────────────────

    public function formattedSubtotal(): string
    {
        return '₱'.number_format($this->subtotalCentavos / 100, 2);
    }

    public function formattedDiscount(): string
    {
        return '-₱'.number_format($this->discountCentavos / 100, 2);
    }

    public function formattedTotal(): string
    {
        return '₱'.number_format($this->totalCentavos / 100, 2);
    }

    /** Applied-voucher chip text, e.g. "ZEFANYANEW (-₱200.00) + SHIPFREE". */
    public function voucherChipLabel(): string
    {
        if (! $this->voucherApplication->hasVoucher()) {
            return '';
        }

        return collect($this->voucherApplication->toArray())
            ->map(function (array $voucher): string {
                return $voucher['discount_minor'] > 0
                    ? $voucher['label']
                    : $voucher['code'];
            })
            ->implode(' + ');
    }

    public function hasVoucher(): bool
    {
        return $this->voucherApplication->hasVoucher();
    }

    /**
     * Per-line totals for every available item, so the page JS can refresh a
     * single row without recomputing money client-side.
     *
     * @return array<int, array{raw: int, formatted: string}>
     */
    public function lineTotals(): array
    {
        return $this->availableItems
            ->mapWithKeys(fn (CartItem $i): array => [
                $i->id => [
                    'raw' => $i->lineTotalCentavos(),
                    'formatted' => $i->formattedLineTotal(),
                ],
            ])
            ->all();
    }

    /**
     * Return a plain array for JSON responses so controllers don't have to
     * reassemble data manually. `$voucherNotice` carries a message when a
     * previously applied voucher was dropped during recalculation.
     *
     * @return array<string, mixed>
     */
    public function toJson(?string $voucherNotice = null): array
    {
        return [
            'subtotal' => $this->formattedSubtotal(),
            'discount' => $this->formattedDiscount(),
            'total' => $this->formattedTotal(),
            'subtotal_raw' => $this->subtotalCentavos,
            'discount_raw' => $this->discountCentavos,
            'total_raw' => $this->totalCentavos,
            // Legacy key retained for clients; item counts are cart lines.
            'selected_qty' => $this->selectedLineCount(),
            'selected_lines' => $this->selectedLineCount(),
            'available_lines' => $this->availableLineCount(),
            'cart_count' => $this->cartLineCount(),
            'line_totals' => $this->lineTotals(),
            'has_voucher' => $this->voucherApplication->hasVoucher(),
            'voucher_code' => $this->voucher?->code,
            'voucher_label' => $this->voucherApplication->hasVoucher() ? $this->voucherChipLabel() : null,
            'voucher_notice' => $voucherNotice,
            'vouchers' => $this->voucherApplication->toArray(),
            'shop_discount_raw' => $this->voucherApplication->shopDiscountCentavos,
            'platform_discount_raw' => $this->voucherApplication->platformDiscountCentavos,
            'shipping_discount_raw' => $this->shippingDiscountCentavos,
            'shipping_discount' => '-₱'.number_format($this->shippingDiscountCentavos / 100, 2),
            'has_shipping_voucher' => $this->voucherApplication->shippingVoucher !== null,
            'shipping_voucher_code' => $this->voucherApplication->shippingVoucher?->code,
            'is_empty' => $this->isEmpty(),
        ];
    }
}
