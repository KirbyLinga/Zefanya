<?php

namespace App\Models\Buyer;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Services\Buyer\CartVariantResolver;
use App\Services\Shared\OfferPricing;
use App\Services\Shared\PriceResolver;
use App\Services\Shared\ResolvedPrice;
use Database\Factories\Buyer\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $fillable = ['buyer_id', 'product_id', 'variant_id', 'quantity'];

    protected $casts = [
        'quantity' => 'integer',
    ];

    protected static function newFactory(): CartItemFactory
    {
        return CartItemFactory::new();
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The specific variant this line targets. NULL on legacy pre-variant lines
     * (and on single-SKU products that never gained option combinations).
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Unit price in centavos for THIS line, including any running sale: the
     * variant's price when the line targets a variant or resolves to its single
     * optionless Default.
     */
    public function unitPriceCentavos(): int
    {
        return $this->resolvedPrice()?->unitCentavos() ?? 0;
    }

    /** The resolver's full answer for this line; null once the product is gone. */
    public function resolvedPrice(): ?ResolvedPrice
    {
        return $this->product === null
            ? null
            : app(PriceResolver::class)->forVariant($this->product, $this->resolvedVariant());
    }

    /**
     * Line total in centavos (integer arithmetic, no float errors).
     */
    public function lineTotalCentavos(): int
    {
        return app(OfferPricing::class)->lineTotal($this);
    }

    /** Split flash, bundle, or Buy X Get Y prices for this line. */
    public function offerNote(): ?string
    {
        $portions = app(OfferPricing::class)->portions($this);
        $single = count($portions) === 1
            && $portions[0]['quantity'] === $this->quantity
            && $portions[0]['price'] === $this->unitPriceCentavos();

        if ($single) {
            return null;
        }

        return collect($portions)
            ->map(fn (array $portion): string => '₱'.number_format($portion['price'] / 100, 2).' × '.$portion['quantity'])
            ->implode(' and ');
    }

    /** Parcel weight of this line. Shipping quotes and the order snapshot both read it. */
    public function lineWeightGrams(): int
    {
        $weight = $this->variant?->effectiveWeightGrams()
            ?? (int) ($this->product?->weight_grams ?? 0);

        return $this->quantity * $weight;
    }

    /**
     * Formatted line total (₱X,XXX.XX).
     */
    public function formattedLineTotal(): string
    {
        return '₱'.number_format($this->lineTotalCentavos() / 100, 2);
    }

    /**
     * Stock ceiling for this line: the variant's stock when one is targeted,
     * otherwise its single optionless Default (zero when ambiguous).
     */
    public function availableQuantity(): int
    {
        $stock = $this->resolvedVariant()?->stock_quantity ?? 0;

        return max(0, (int) $stock);
    }

    /**
     * True when the linked product AND variant are purchaseable (active, not
     * soft-deleted, stock > 0, variant enabled). Used by the cart page to dim
     * unavailable rows.
     */
    public function isAvailable(): bool
    {
        $p = $this->product;

        if ($p === null || ! $p->isPurchasable()) {
            return false;
        }

        $variant = $this->resolvedVariant();

        return $variant !== null && $variant->is_available && $variant->stock_quantity > 0;
    }

    /** Presentation only: prefer the selected variant's existing photo. */
    public function cartImageUrl(): ?string
    {
        $product = $this->product;
        if ($product === null) {
            return null;
        }
        $variant = $this->resolvedVariant();

        return ($variant === null ? null : $product->catalogImageUrl(
            $product->images->where('variant_id', $variant->id),
        )) ?? $product->catalogImageUrl();
    }

    public function cartVariantLabel(): ?string
    {
        $variant = $this->resolvedVariant();

        return $variant?->hasOptions() ? $variant->label() : null;
    }

    public function requiresVariantReselection(): bool
    {
        return $this->variant_id === null && $this->product !== null
            && app(CartVariantResolver::class)->defaultVariant($this->product) === null;
    }

    public function isVariationUnavailable(): bool
    {
        $variant = $this->resolvedVariant();

        return $this->product !== null && $this->product->isListedForSale()
            && ($variant === null || ! $variant->is_available);
    }

    private function resolvedVariant(): ?ProductVariant
    {
        if ($this->product === null) {
            return null;
        }
        if ($this->variant_id !== null) {
            return $this->variant?->product_id === $this->product_id ? $this->variant : null;
        }

        return app(CartVariantResolver::class)->defaultVariant($this->product);
    }
}
