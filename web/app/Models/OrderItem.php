<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_order_id',
        'product_id',
        'product_name',
        'quantity',
        'price_minor',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price_minor' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * Items hang off seller_orders, never directly off orders.
     */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    /**
     * The product that was purchased (nullable FK — the line survives as an
     * audit record even if the product is removed later).
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Line total in centavos (integer arithmetic, no float errors).
     */
    public function lineTotalMinor(): int
    {
        return $this->quantity * $this->price_minor;
    }

    public function formattedLineTotal(): string
    {
        return '₱'.number_format($this->lineTotalMinor() / 100, 2);
    }

    public function formattedUnitPrice(): string
    {
        return '₱'.number_format($this->price_minor / 100, 2);
    }
}
