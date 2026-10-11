<?php

namespace App\Models;

use App\Models\Buyer\Buyer;
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
     * targets a variant, otherwise the product rollup price.
     */
    public function unitPriceCentavos(): int
    {
        $price = $this->variant?->price ?? $this->product?->price ?? 0;

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
     * otherwise the product rollup.
     */
    public function availableQuantity(): int
    {
        $stock = $this->variant_id !== null
            ? ($this->variant?->stock_quantity ?? 0)
            : ($this->product?->stock_quantity ?? 0);

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

        if ($p === null || $p->trashed() || $p->status !== 'active') {
            return false;
        }

        if ($this->variant_id !== null) {
            $v = $this->variant;

            return $v !== null && $v->is_available && $v->stock_quantity > 0;
        }

        return $p->stock_quantity > 0;
    }
}
