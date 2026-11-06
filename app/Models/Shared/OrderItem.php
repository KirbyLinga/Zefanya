<?php

namespace App\Models\Shared;

use App\Models\Buyer\Review;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_order_id',
        'product_id',
        'variant_id',
        'product_name',
        'variant_options',
        'quantity',
        'price_minor',
        'regular_price_minor',
        'store_sale_id',
    ];

    protected function casts(): array
    {
        return [
            'variant_options' => 'array',
            'quantity' => 'integer',
            'price_minor' => 'integer',
            'regular_price_minor' => 'integer',
            'store_sale_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Keep the parcel weight snapshot in sync whenever lines are created,
        // changed, or removed. Product weight edits alone do not fire this, so
        // an in-transit parcel keeps the weight it was checked out with.
        static::saved(fn (OrderItem $item) => $item->sellerOrder?->refreshParcelWeight());
        static::deleted(fn (OrderItem $item) => $item->sellerOrder?->refreshParcelWeight());
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
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * The variant that was purchased. NULL on legacy order lines — an
     * immutable snapshot may legitimately predate variants.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** BUY-RATE-003: one review per purchased item. */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Display only saved purchase options, never live catalog values.
     */
    public function variantDescription(): string
    {
        // Never infer historical selections from a mutable product/variant.
        return collect($this->variant_options ?? [])
            ->filter(fn ($option) => is_array($option) && filled($option['name'] ?? null) && filled($option['value'] ?? null))
            ->map(fn ($option) => $option['name'].': '.$option['value'])
            ->implode(' / ');
    }

    /** Line total in centavos (integer arithmetic, no float errors). */
    public function lineTotalMinor(): int
    {
        return $this->quantity * $this->price_minor;
    }

    public function formattedLineTotal(): string
    {
        return '₱'.number_format($this->lineTotalMinor() / 100, 2);
    }
}
