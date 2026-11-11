<?php

namespace App\Models\Logistics;

use App\Enums\ParcelReturnStatus;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Support\RuntimeSchema;
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

    /** The Seller pays the refund and return shipping (wrong, defective, not as described). */
    public const FAULT_SELLER = 'seller';

    /** Damaged in transit: the Logistics company pays and the Seller is not charged. */
    public const FAULT_LOGISTICS = 'logistics';

    /** The buyer reason that names no fault, so an admin must decide who pays. */
    public const REASON_OTHER = 'Other';

    /** Failed buyer collections allowed before the return goes to Admin. */
    public const MAX_COLLECTION_ATTEMPTS = 2;

    /** Why a rider could not collect a buyer's return. */
    public const COLLECTION_FAILURE_REASONS = [
        'buyer_absent' => 'Buyer not at the address',
        'parcel_unavailable' => 'Buyer did not have the parcel ready',
        'buyer_refused' => 'Buyer refused to hand over the parcel',
        'address_problem' => 'Address wrong or unreachable',
        'other' => 'Other',
    ];

    /** What a Seller can report instead of confirming physical receipt. */
    public const RECEIPT_DISPUTE_ISSUES = [
        'wrong_item' => 'Wrong item returned',
        'damaged' => 'Item damaged',
        'refused' => 'I refuse this parcel',
    ];

    /** What physically happened to a parcel whose Seller receipt was disputed or closed. */
    public const DISPOSITIONS = [
        'returned_to_seller' => 'Handed back to the Seller',
        'returned_to_buyer' => 'Sent back to the Buyer',
        'destroyed' => 'Destroyed or written off',
        'held_by_logistics' => 'Still held by Logistics',
        'other' => 'Other',
    ];

    /** True once the recovery migration has run; the live site degrades without it. */
    public static function recoveryAvailable(): bool
    {
        return RuntimeSchema::hasColumn('parcel_returns', 'closed_at');
    }

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
        return ['status' => ParcelReturnStatus::class, 'delivery_attempts' => 'integer', 'seller_address' => 'array', 'requested_at' => 'datetime', 'origin_arrived_at' => 'datetime', 'seller_received_at' => 'datetime', 'handover_expires_at' => 'datetime', 'stock_restored_at' => 'datetime', 'financial_review_required' => 'boolean', 'photos' => 'array', 'seller_response_due_at' => 'datetime', 'seller_decided_at' => 'datetime', 'collection_assigned_at' => 'datetime', 'collected_at' => 'datetime', 'escalated_at' => 'datetime', 'dispute_resolved_at' => 'datetime', 'refunded_at' => 'datetime', 'refund_amount_minor' => 'integer', 'refund_paid_on' => 'date', 'fault_set_at' => 'datetime', 'return_shipping_fee_minor' => 'integer',
            'collection_failed_attempts' => 'integer', 'collection_last_failed_at' => 'datetime', 'collection_escalated_at' => 'datetime',
            'collection_retry_authorized_at' => 'datetime', 'disposition_set_at' => 'datetime',
            'closed_at' => 'datetime', 'receipt_dispute_photos' => 'array', 'receipt_disputed_at' => 'datetime', 'earnings_restored_at' => 'datetime'];
    }

    /** A buyer's own request, as opposed to a failed delivery or incident going back to the Seller. */
    public function isBuyerRequest(): bool
    {
        return $this->seller_response_due_at !== null;
    }

    public function isLogisticsFault(): bool
    {
        return $this->fault_party === self::FAULT_LOGISTICS;
    }

    /** "Other" names no fault, so no refund until an admin decides who pays. */
    public function needsAdminFaultDecision(): bool
    {
        return $this->isBuyerRequest() && $this->reason === self::REASON_OTHER && $this->fault_set_by_admin_id === null;
    }

    /** Who pays can change until the refund is recorded or the request is rejected. */
    public function faultCanChange(): bool
    {
        return $this->refunded_at === null && ! in_array($this->status, [ParcelReturnStatus::Rejected, ParcelReturnStatus::Cancelled], true);
    }

    public function faultLabel(): string
    {
        return match ($this->fault_party) {
            self::FAULT_SELLER => 'Seller (wrong, defective or not as described)',
            self::FAULT_LOGISTICS => 'Logistics (damaged in transit)',
            default => 'Not decided yet',
        };
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

    /** Money this return moves between Zefanya and Logistics companies. */
    public function returnCharges(): HasMany
    {
        return $this->hasMany(LogisticsReturnCharge::class)->orderBy('id');
    }

    /** True for a failed delivery sent back to the Seller, not a buyer's own request. */
    public function isFailedDeliveryReturn(): bool
    {
        return ! $this->isBuyerRequest() && (int) $this->delivery_attempts > 0;
    }

    public function events(): HasMany
    {
        return $this->hasMany(ParcelReturnEvent::class)->orderBy('id');
    }

    public function isActive(): bool
    {
        return ! $this->status->isTerminal();
    }

    /**
     * The shipment cannot be completed while the return is open, nor after the
     * parcel left the buyer and never reached the Seller (lost, damaged, closed
     * dispute). A rejected or cancelled return leaves the item with the buyer.
     */
    public function blocksCompletion(): bool
    {
        return $this->isActive()
            || in_array($this->status, [ParcelReturnStatus::Lost, ParcelReturnStatus::Damaged, ParcelReturnStatus::Closed], true);
    }

    /** Closed before collection: the buyer kept the item. */
    public function isCancelled(): bool
    {
        return $this->status === ParcelReturnStatus::Cancelled;
    }

    /** Two failed collections: only Admin can decide what happens next. */
    public function collectionEscalated(): bool
    {
        return $this->collection_escalated_at !== null;
    }

    /** Admin authorized one extra buyer collection after the normal limit. */
    public function collectionRetryAuthorized(): bool
    {
        return $this->collection_retry_authorized_at !== null;
    }

    /** One additional collection may be assigned only at the exact escalation limit. */
    public function canRetryCollection(): bool
    {
        return $this->collectionRetryAuthorized()
            && (int) $this->collection_failed_attempts === self::MAX_COLLECTION_ATTEMPTS;
    }

    public function dispositionLabel(): string
    {
        return self::DISPOSITIONS[$this->disposition] ?? 'Not recorded';
    }

    /** Physical disposition can be recorded while receipt is disputed or after a terminal close. */
    public function dispositionCanBeRecorded(): bool
    {
        return $this->receiptDisputed()
            || in_array($this->status, [ParcelReturnStatus::Closed, ParcelReturnStatus::Lost, ParcelReturnStatus::Damaged], true);
    }

    /** The buyer may withdraw a request the rider has not collected yet, unless a refund is recorded. */
    public function canBuyerCancel(): bool
    {
        return $this->isBuyerRequest()
            && $this->status === ParcelReturnStatus::Requested
            && $this->collected_at === null
            && $this->refunded_at === null;
    }

    /** The parcel is physically inside the Logistics network on its way back. */
    public function inReturnNetwork(): bool
    {
        return match ($this->status) {
            ParcelReturnStatus::Requested => ! $this->isBuyerRequest() || $this->collected_at !== null,
            ParcelReturnStatus::InTransit, ParcelReturnStatus::AtOrigin, ParcelReturnStatus::AwaitingSeller, ParcelReturnStatus::ReceiptDisputed => true,
            default => false,
        };
    }

    public function receiptDisputed(): bool
    {
        return $this->status === ParcelReturnStatus::ReceiptDisputed;
    }

    public function collectionFailureLabel(?string $key): string
    {
        return self::COLLECTION_FAILURE_REASONS[$key] ?? 'Other';
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
        // Cancelled before collection with no refund: the buyer kept the item, so the Seller is owed the sale again.
        if ($this->isCancelled() && $this->refunded_at === null) {
            return false;
        }

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
