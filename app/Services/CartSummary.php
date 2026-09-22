<?php

namespace App\Services;

use App\Models\CartItem;
use App\Models\Seller\Seller;
use App\Models\Voucher;
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

    public readonly int $totalCentavos;

    public readonly ?Voucher $voucher;

    /** @var Collection<int, CartItem>  Every line in the cart, available or not */
    private readonly Collection $allItems;

    /** @var array<int>|null null = all available items, [] = nothing selected */
    private readonly ?array $selectedIds;

    public function __construct(
        /** @param Collection<int, CartItem> $allItems All cart items, already loaded with product.images + product.seller + product.category */
        Collection $allItems,
        ?Voucher $voucher = null,
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
        $this->sellerGroups = $this->availableItems
            ->groupBy(fn (CartItem $i): int => (int) $i->product->seller_id)
            ->map(fn (Collection $group): array => [
                'seller' => $group->first()->product->seller,
                'items' => $group->values(),
            ])
            ->values();

        // Totals computed over selected items only
        $this->subtotalCentavos = (int) $this->selectedItems()
            ->sum(fn (CartItem $i): int => $i->lineTotalCentavos());

        $this->voucher = $voucher;
        $this->discountCentavos = $voucher !== null
            ? $voucher->discountCentavos($this->subtotalCentavos)
            : 0;

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

    /** Total quantity of the SELECTED items (the "N items" pill). */
    public function selectedQuantity(): int
    {
        return (int) $this->selectedItems()->sum('quantity');
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
     * Total quantity of EVERY line, available or not — this is the navbar badge,
     * so the shopper still sees items that went out of stock.
     */
    public function cartQuantity(): int
    {
        return (int) $this->allItems->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return $this->allItems->isEmpty();
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

    /** Applied-voucher chip text, e.g. "ZEFANYANEW (-₱200.00)". */
    public function voucherChipLabel(): string
    {
        if ($this->voucher === null) {
            return '';
        }

        return $this->voucher->code.' ('.$this->formattedDiscount().')';
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
            'selected_qty' => $this->selectedQuantity(),
            'selected_lines' => $this->selectedLineCount(),
            'available_lines' => $this->availableLineCount(),
            'cart_count' => $this->cartQuantity(),
            'line_totals' => $this->lineTotals(),
            'has_voucher' => $this->voucher !== null,
            'voucher_code' => $this->voucher?->code,
            'voucher_label' => $this->voucher !== null ? $this->voucherChipLabel() : null,
            'voucher_notice' => $voucherNotice,
            'is_empty' => $this->isEmpty(),
        ];
    }
}
