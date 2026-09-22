<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * `status` is deliberately NOT mass-assignable — pipeline transitions go
     * through controlled business logic in later phases, never request data.
     */
    protected $fillable = [
        'buyer_id',
        'reference',
        'shipping_address',
        'subtotal_minor',
        'discount_minor',
        'total_minor',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'status' => OrderStatus::class,
        ];
    }

    // ── Reference generation ─────────────────────────────────────────────────

    /**
     * Human-facing order number, e.g. ORD-20260922-4F8K2Q. The unique index on
     * orders.reference is the final guard; this loop avoids the (rare) collision
     * up front.
     */
    public static function generateUniqueReference(): string
    {
        do {
            $reference = 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * A buyer is a row in the buyers table (orders.buyer_id → buyers.id).
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'buyer_id');
    }

    /**
     * Per-seller fulfilment units created from this checkout.
     */
    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    // ── Formatting helpers (₱X,XXX.XX from integer centavos) ─────────────────

    public function formattedSubtotal(): string
    {
        return '₱'.number_format($this->subtotal_minor / 100, 2);
    }

    public function formattedDiscount(): string
    {
        return '-₱'.number_format($this->discount_minor / 100, 2);
    }

    public function formattedTotal(): string
    {
        return '₱'.number_format($this->total_minor / 100, 2);
    }
}
