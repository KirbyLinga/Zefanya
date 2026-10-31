<?php

namespace App\Models\Logistics;

use App\Enums\ParcelReturnStatus;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParcelReturn extends Model
{
    public const SELLER_DECISION_DAYS = 3;

    public const APPROVED = 'approved';

    public const AUTO_APPROVED = 'auto_approved';

    public const REJECTED = 'rejected';

    public const DISPUTE_UPHELD = 'upheld';

    public const DISPUTE_OVERTURNED = 'overturned';

    protected $fillable = [
        'seller_order_id',
        'status',
        'origin_provider_id',
        'origin_hub_id',
        'reason',
        'requested_at',
        'barangay_code',
        'address',
        'evidence',
    ];

    protected $hidden = ['handover_code_hash', 'handover_nonce'];

    protected function casts(): array
    {
        return ['status' => ParcelReturnStatus::class, 'delivery_attempts' => 'integer', 'seller_address' => 'array', 'requested_at' => 'datetime', 'origin_arrived_at' => 'datetime', 'seller_received_at' => 'datetime', 'handover_expires_at' => 'datetime', 'stock_restored_at' => 'datetime', 'financial_review_required' => 'boolean', 'photos' => 'array', 'seller_response_due_at' => 'datetime', 'seller_decided_at' => 'datetime', 'collection_assigned_at' => 'datetime', 'collected_at' => 'datetime', 'escalated_at' => 'datetime', 'dispute_resolved_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function originProvider(): BelongsTo
    {
        return $this->belongsTo(LogisticsProvider::class, 'origin_provider_id');
    }

    public function originHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'origin_hub_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ParcelReturnEvent::class)->orderBy('id');
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, [ParcelReturnStatus::Completed, ParcelReturnStatus::Rejected], true);
    }

    /** A buyer's request the Seller has not answered. Nothing physical moves until they do. */
    public function awaitingSellerDecision(): bool
    {
        return $this->seller_response_due_at !== null && $this->seller_decision === null;
    }

    /** An accepted buyer return still at the buyer's address: someone has to go and fetch it. */
    public function awaitingBuyerCollection(): bool
    {
        return $this->seller_response_due_at !== null
            && $this->seller_decision !== null
            && $this->status === ParcelReturnStatus::Requested
            && $this->collected_at === null;
    }

    public function collectedFromBuyer(): bool
    {
        return $this->collected_at !== null;
    }

    /** Why this return cannot be moved through the network yet; null when it can. */
    public function movementBlockedReason(): ?string
    {
        return match (true) {
            $this->awaitingSellerDecision() => 'The Seller has not decided this buyer return request yet.',
            $this->awaitingBuyerCollection() => 'This parcel is still with the buyer. A rider must collect it first.',
            default => null,
        };
    }

    public function collectionCourier(): BelongsTo
    {
        return $this->belongsTo(Courier::class, 'collection_courier_id');
    }

    public function sellerDecisionOverdue(): bool
    {
        return $this->awaitingSellerDecision() && ! $this->seller_response_due_at->isFuture();
    }

    public function inDispute(): bool
    {
        return $this->status === ParcelReturnStatus::InDispute;
    }

    /**
     * The shipment's still-pending earnings and commission leave once the
     * return is approved, an admin overturns a rejection, or a refund is recorded.
     */
    public function releasesPendingEarnings(): bool
    {
        return in_array($this->seller_decision, [self::APPROVED, self::AUTO_APPROVED], true)
            || $this->dispute_outcome === self::DISPUTE_OVERTURNED
            || $this->refunded_at !== null;
    }

    /** A seller rejection the buyer has not yet asked an admin to review. */
    public function canBuyerEscalate(): bool
    {
        return $this->status === ParcelReturnStatus::Rejected
            && $this->seller_decision === self::REJECTED
            && $this->escalated_at === null;
    }

    public function scopeVisibleToProvider(Builder $query, int $providerId): Builder
    {
        return $query->where(fn ($visible) => $visible->where('origin_provider_id', $providerId)
            ->orWhereHas('sellerOrder', fn ($parcel) => $parcel->heldBy($providerId))
            ->orWhereHas('sellerOrder.parcelHandoffs', fn ($handoff) => $handoff->where('direction', 'return')->where('origin_provider_id', $providerId)));
    }
}
