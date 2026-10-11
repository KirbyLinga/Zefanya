<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class SellerOrder extends Model
{
    /** @use HasFactory<SellerOrderFactory> */
    use HasFactory;

    /**
     * `status` is deliberately NOT mass-assignable — pipeline transitions go
     * through controlled business logic in later phases, never request data.
     */
    protected $fillable = [
        'order_id',
        'seller_id',
        'subtotal_minor',
        'tracking_number',
        'courier_id',
        'delivery_courier_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'status' => OrderStatus::class,
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * The checkout-level order this fulfilment unit belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The shop fulfilling this slice of the order.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Payment is per seller_order, not per order.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * The courier assigned to pick up and deliver this seller_order.
     */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /**
     * The delivery courier assigned at the sorting center (the pickup courier
     * may be a different rider).
     */
    public function deliveryCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'delivery_courier_id');
    }

    /**
     * Full status-change timeline (append-only audit log).
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(StatusHistory::class)->orderBy('created_at');
    }

    /**
     * Total parcel weight in grams: sum of each line item's effective weight
     * multiplied by its quantity. A variant overrides the product weight when
     * it carries its own weight_grams; otherwise the product's applies.
     */
    public function parcelWeightGrams(): int
    {
        return (int) $this->orderItems
            ->sum(function (OrderItem $item): int {
                $weight = $item->variant?->effectiveWeightGrams()
                    ?? (int) ($item->product?->weight_grams ?? 0);

                return $item->quantity * $weight;
            });
    }

    /**
     * The buyer's destination municipality, read from the order's immutable
     * shipping-address snapshot. Used for service-area matching.
     */
    public function buyerMunicipality(): ?string
    {
        return $this->order?->shipping_address['municipality']
            ?? $this->order?->shipping_address['municipality_name']
            ?? null;
    }

    /**
     * True when this parcel has been claimed by the given provider's fleet
     * (its pickup courier belongs to the provider).
     */
    public function isClaimedBy(LogisticsProvider $provider): bool
    {
        return $this->courier !== null
            && (int) $this->courier->logistics_provider_id === (int) $provider->getKey();
    }

    /**
     * Human-readable parcel weight for display, e.g. "1.2 kg" or "350 g".
     */
    public function formattedParcelWeight(): string
    {
        $grams = $this->parcelWeightGrams();

        if ($grams >= 1000) {
            $kg = $grams / 1000;

            return rtrim(rtrim(number_format($kg, 2, '.', ''), '0'), '.').' kg';
        }

        return $grams.' g';
    }

    /**
     * Generate a unique, human-readable tracking number.
     * Format: TRK-YYYYMMDD-XXXXXX (6 random chars, collision-safe loop).
     */
    public static function generateUniqueTrackingNumber(): string
    {
        do {
            $candidate = 'TRK-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        } while (self::query()->where('tracking_number', $candidate)->exists());

        return $candidate;
    }

    // ── Formatting helpers ───────────────────────────────────────────────────

    public function formattedSubtotal(): string
    {
        return '₱'.number_format($this->subtotal_minor / 100, 2);
    }
}
