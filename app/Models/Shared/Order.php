<?php

namespace App\Models\Shared;

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
        'shop_discount_minor',
        'platform_discount_minor',
        'shipping_discount_minor',
        'total_minor',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'shop_discount_minor' => 'integer',
            'platform_discount_minor' => 'integer',
            'shipping_discount_minor' => 'integer',
            'total_minor' => 'integer',
            'status' => OrderStatus::class,
        ];
    }

    // ── Status rollup ────────────────────────────────────────────────────────

    /**
     * Returned / cancelled shipments are resolved without becoming completed.
     * A fully resolved mixed order is Completed; an all-returned order is Returned;
     * an all-cancelled order is Cancelled.
     */
    public function computeRollupStatus(): ?OrderStatus
    {
        $statuses = $this->sellerOrders->pluck('status')->filter()->values();
        if ($statuses->isEmpty()) {
            return null;
        }
        $resolved = [OrderStatus::Completed, OrderStatus::Returned, OrderStatus::Cancelled];
        $unresolved = $statuses->reject(fn (OrderStatus $status): bool => in_array($status, $resolved, true));
        if ($unresolved->isEmpty()) {
            if ($statuses->every(fn (OrderStatus $status): bool => $status === OrderStatus::Returned)) {
                return OrderStatus::Returned;
            }
            if ($statuses->every(fn (OrderStatus $status): bool => $status === OrderStatus::Cancelled)) {
                return OrderStatus::Cancelled;
            }

            return OrderStatus::Completed;
        }
        if ($unresolved->contains(OrderStatus::DeliveryFailed)) {
            return OrderStatus::DeliveryFailed;
        }
        $ranks = array_flip(array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::cases()));

        return $unresolved->sortBy(fn (OrderStatus $status): int => $ranks[$status->value])->first();
    }

    /**
     * Persist the rollup. `status` is deliberately NOT in $fillable — it is
     * written here via forceFill, the same pattern CheckoutController uses
     * for the initial PLACED value. No-op if the status is already correct.
     */
    public function rollUpStatus(): self
    {
        $rolledUp = $this->computeRollupStatus();

        if ($rolledUp !== null && $this->status !== $rolledUp) {
            $this->forceFill(['status' => $rolledUp])->save();
        }

        return $this;
    }

    /** Any delivered shipment may be confirmed independently of its siblings. */
    public function canBeConfirmed(): bool
    {
        return $this->sellerOrders->contains(fn (SellerOrder $shipment) => $shipment->status === OrderStatus::Delivered && ! $shipment->hasActiveReturn());
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
