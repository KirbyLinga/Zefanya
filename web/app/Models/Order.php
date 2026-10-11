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

    // ── Status rollup ────────────────────────────────────────────────────────

    /**
     * Compute the parent order's status from its seller_orders WITHOUT saving.
     *
     * - All seller_orders Completed → Completed.
     * - Otherwise → the least-advanced (bottleneck) status among the
     *   non-Completed seller_orders. Completed siblings are excluded so they
     *   never mask a slice that is still in flight or in the failure sub-pipeline.
     *
     * Pipeline rank = position in OrderStatus::cases() declaration order:
     *   Placed=0 → Confirmed=1 → … → Delivered=9 → Completed=10
     *   → DeliveryFailed=11 → Returned=12
     *
     * DELIVERY_FAILED (11) and RETURNED (12) sit AFTER Completed (10) on the
     * enum's declaration order. Because we exclude Completed from the min,
     * these ranks ensure a problem slice is never masked by a Completed sibling.
     * Against a Delivered sibling (rank 9), DeliveryFailed (11) ranks higher —
     * this is a known limitation: in the failure sub-pipeline a failed delivery
     * is arguably "less progressed" than a successful one, but the rank-based
     * approach still surfaces Delivered as the bottleneck, which is correct for
     * the common multi-seller case (some delivered, some still shipping).
     */
    public function computeRollupStatus(): ?OrderStatus
    {
        $statuses = $this->sellerOrders->pluck('status')->filter()->values();

        if ($statuses->isEmpty()) {
            return null;
        }

        if ($statuses->every(fn (OrderStatus $s): bool => $s === OrderStatus::Completed)) {
            return OrderStatus::Completed;
        }

        // Exclude Completed: a completed sibling must not hide an in-progress slice.
        $ranks = array_flip(array_map(fn (OrderStatus $s): string => $s->value, OrderStatus::cases()));
        $nonCompleted = $statuses->filter(fn (OrderStatus $s): bool => $s !== OrderStatus::Completed);
        $minRank = $nonCompleted->map(fn (OrderStatus $s) => $ranks[$s->value])->min();

        return OrderStatus::cases()[$minRank];
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

    /**
     * Whether the buyer can confirm receipt of this order right now.
     *
     * True only when EVERY seller_order is at Delivered or Completed AND
     * at least one is still at Delivered (i.e. there is at least one slice
     * the buyer hasn't confirmed yet, and no slice is left stuck behind on
     * an earlier pipeline state).
     */
    public function canBeConfirmed(): bool
    {
        $statuses = $this->sellerOrders->pluck('status')->filter()->values();

        if ($statuses->isEmpty()) {
            return false;
        }

        // At least one slice must still be awaiting confirmation.
        if (! $statuses->contains(OrderStatus::Delivered)) {
            return false;
        }

        // No slice may be left behind on the pre-delivery pipeline.
        return $statuses->every(fn (OrderStatus $s): bool => in_array($s, [OrderStatus::Delivered, OrderStatus::Completed], true));
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
