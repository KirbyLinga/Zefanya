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

    protected $fillable = ['buyer_id', 'product_id', 'quantity'];

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

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Line total in centavos (integer arithmetic, no float errors).
     */
    public function lineTotalCentavos(): int
    {
        return (int) bcmul(
            bcmul((string) $this->product->price, '100', 0),
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
     * True when the linked product is purchaseable (active, not soft-deleted,
     * stock > 0). Used by the cart page to dim unavailable rows.
     */
    public function isAvailable(): bool
    {
        $p = $this->product;

        return $p !== null
            && ! $p->trashed()
            && $p->status === 'active'
            && $p->stock_quantity > 0;
    }
}
