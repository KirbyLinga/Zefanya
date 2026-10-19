<?php

namespace App\Models;

use App\Models\Buyer\Buyer;
use App\Services\CartVariantResolver;
use Database\Factories\CartItemFactory;
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
     * Unit price in centavos for THIS line: the variant's price when the line
     * targets a variant or resolves to its single optionless Default.
     */
    public function unitPriceCentavos(): int
    {
        $price = $this->resolvedVariant()?->price ?? $this->product?->price ?? 0;

        return (int) bcmul((string) $price, '100', 0);
    }

    /**
     * Line total in centavos (integer arithmetic, no float errors).
     */
    public function lineTotalCentavos(): int
    {
        return (int) bcmul(
            (string) $this->unitPriceCentavos(),
            (string) $this->quantity,
            0,
        );
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

        if ($p === null || ! $p->isListedForSale()) {
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
