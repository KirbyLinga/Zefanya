<?php

namespace App\Models\Logistics;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentStatus;
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

    /** Exception refunds only: Zefanya absorbs the refund as a platform expense. */
    public const FAULT_PLATFORM = 'platform';

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

    /** Explicit deployment activation; existing paid records are never backfilled. */
    public static function refundPolicyAvailable(): bool
    {
        return RuntimeSchema::hasColumn('parcel_returns', 'refund_policy_version');
    }

    public function hasVerifiedRefundReceipt(): bool
    {
        return $this->seller_received_at !== null && $this->received_by_seller_id !== null
            && (int) $this->received_by_seller_id === (int) $this->sellerOrder->seller_id;
    }

    /**
     * Normal-path custody evidence from recorded events only (never status text, never backfilled).
     * Buyer-collected returns need collected_from_buyer then station_received at the same station,
     * in that order, before any later origin event. Returns that never entered buyer collection
     * (failed-delivery returns travelling back to the Seller) need the recorded at_origin event.
     * Returns null when the custody chain is sound; otherwise says what is missing.
     */
    public function refundCustodyProblem(): ?string
    {
        $events = $this->events()->reorder('id')->get(['id', 'event_key', 'occurred_at', 'metadata']);
        $first = fn (string $key) => $events->firstWhere('event_key', $key);
        if (! $this->isBuyerRequest()) {
            return $first('at_origin') === null || $this->origin_arrived_at === null ? 'No recorded origin-station receipt for this return.' : null;
        }
        $collected = $first('collected_from_buyer');
        $stationEvents = $events->filter(fn ($e) => str_starts_with($e->event_key, 'station_received'));
        $station = $stationEvents->first();
        if ($collected === null) {
            return 'No recorded rider collection from the buyer.';
        }
        if ($station === null) {
            return 'No recorded Logistics station receipt.';
        }
        if ($stationEvents->contains(fn ($e) => (int) ($e->metadata['station_hub_id'] ?? 0) !== (int) ($station->metadata['station_hub_id'] ?? -1))) {
            return 'Conflicting station receipts are recorded.';
        }
        if ((int) ($collected->metadata['station_hub_id'] ?? 0) !== (int) ($station->metadata['station_hub_id'] ?? -1)
            || (int) ($collected->metadata['provider_id'] ?? 0) !== (int) ($station->metadata['provider_id'] ?? -1)) {
            return 'Station receipt does not match the recorded collection station.';
        }
        if ($station->id < $collected->id || $station->occurred_at->lt($collected->occurred_at)) {
            return 'Station receipt is recorded before the rider collection.';
        }
        $origin = $first('at_origin');
        if ($origin !== null && $this->origin_arrived_at === null) {
            return 'Origin receipt event has no matching verified origin arrival.';
        }
        if ($origin !== null && ($origin->id < $station->id || $origin->occurred_at->lt($station->occurred_at))) {
            return 'Origin receipt is recorded before the station receipt.';
        }

        return null;
    }

    /** Why the normal (non-exception) refund path is not yet open; null when it is. */
    public function refundNormalPathBlocker(): ?string
    {
        if (! $this->refundApproved()) {
            return 'The return is not approved.';
        }

        return $this->hasVerifiedRefundReceipt() ? $this->refundCustodyProblem() : 'The owning Seller has not confirmed receipt.';
    }

    /** Actor and time for each refund step of the current cycle. Manual Admin steps, not bank verification. */
    public function refundStepActors(): array
    {
        $step = fn (?ParcelReturnEvent $e) => $e === null ? null : ['admin_id' => $e->actor_id, 'at' => $e->occurred_at];
        $authorization = $this->currentRefundAuthorization();
        $recorded = $authorization === null ? null : $this->events()->where('event_key', 'like', 'refund_recorded%')
            ->where('metadata->authorization_id', $authorization->id)->reorder('id', 'desc')->first();

        return ['authorization' => $step($authorization), 'evidence' => $step($this->currentRefundSubmission()), 'confirmation' => $step($recorded)];
    }

    /** True when one Admin did every step recorded so far (and, if given, will do the next one). */
    public function refundHandledByOneAdmin(?int $nextAdminId = null): bool
    {
        $ids = collect($this->refundStepActors())->filter()->pluck('admin_id');
        if ($nextAdminId !== null) {
            $ids->push($nextAdminId);
        }

        return $ids->count() >= 3 && $ids->unique()->count() === 1;
    }

    public function currentRefundAuthorization(): ?ParcelReturnEvent
    {
        $cycle = (int) $this->refundHistory()->reorder('id', 'desc')->value('id');

        return $this->events()->where('event_key', 'like', 'refund_authorized%')
            ->where('metadata->cycle', $cycle)->reorder('id', 'desc')->first();
    }

    public function currentRefundSubmission(): ?ParcelReturnEvent
    {
        $authorization = $this->currentRefundAuthorization();

        return $authorization === null ? null : $this->events()->where('event_key', 'like', 'refund_evidence_submitted%')
            ->where('metadata->authorization_id', $authorization->id)->reorder('id', 'desc')->first();
    }

    /** Safe public wording: private payer, account and proof details stay with Admin. */
    public function refundPaymentLabel(bool $internal = false): string
    {
        if ($this->refunded_at !== null) {
            return (int) $this->getAttribute('refund_policy_version') === 1
                ? 'Refund payment confirmed'.($internal ? ' - Admin evidence review, not independent bank verification' : '') : 'Historical refund recorded'.($internal ? ' - payment evidence requires manual review' : '');
        }
        if (self::refundPolicyAvailable() && $this->currentRefundSubmission() !== null) {
            return 'Payment evidence submitted - awaiting verification';
        }
        if (self::refundPolicyAvailable() && $this->currentRefundAuthorization() !== null) {
            return 'Refund authorized - payment pending';
        }

        if ($this->events()->where('event_key', 'like', 'refund_voided%')->exists()) {
            return 'Refund transfer failed - payment pending review';
        }

        return $this->hasVerifiedRefundReceipt() && $this->refundApproved() ? 'Refund eligible for authorization'
            : ($this->refundApproved() ? 'Return approved - awaiting return receipt'.($internal ? '; early exceptions require Admin review' : '') : 'Return decision pending');
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

    /** Zefanya chose to fund an exception refund as its own expense. */
    public function isPlatformFunded(): bool
    {
        return $this->fault_party === self::FAULT_PLATFORM;
    }

    /** Logistics and Platform funding both leave the Seller uncharged. */
    public function sellerNotCharged(): bool
    {
        return $this->isLogisticsFault() || $this->isPlatformFunded();
    }

    public function platformRefundExpenses(): HasMany
    {
        return $this->hasMany(PlatformRefundExpense::class)->orderBy('id');
    }

    /** Documented grounds for recording a refund on a buyer return that was not approved. */
    public const REFUND_EXCEPTION_BASES = [
        'legal_requirement' => 'Legally required refund',
        'separate_approval' => 'Separately approved refund',
    ];

    /**
     * An ordinary refund needs an approved buyer return: Seller approval, automatic
     * approval or an Admin overturn. Logistics and Admin returns have no Seller decision.
     */
    public function refundApproved(): bool
    {
        return ! $this->isBuyerRequest()
            || in_array($this->seller_decision, [self::APPROVED, self::AUTO_APPROVED], true)
            || $this->dispute_outcome === self::DISPUTE_OVERTURNED;
    }

    /** A documented refund exception was authorized at some point (it stays owed after a void). */
    public function hasAuthorizedRefundException(): bool
    {
        return $this->events()->where(fn ($query) => $query
            ->where(fn ($q) => $q->where('event_key', 'like', 'refund_recorded%')->whereNotNull('metadata->exception'))
            ->orWhere(fn ($q) => $q->where('event_key', 'like', 'refund_authorized%')->whereNotNull('metadata->exception_basis')))->exists();
    }

    /** The Buyer is owed a refund: an approved return, or an authorized exception refund. */
    public function refundObligationEstablished(): bool
    {
        return $this->refundApproved() || $this->hasAuthorizedRefundException();
    }

    /** "Other" names no fault, so no refund until an admin decides who pays. */
    public function needsAdminFaultDecision(): bool
    {
        return $this->isBuyerRequest() && $this->reason === self::REASON_OTHER && $this->fault_set_by_admin_id === null;
    }

    /** Who pays can change until the refund is recorded or the request is rejected. */
    public function faultCanChange(): bool
    {
        // A Platform decision is made only with an exception refund and is not re-decided here.
        return $this->refunded_at === null && (! self::refundPolicyAvailable() || $this->currentRefundAuthorization() === null) && ! $this->isPlatformFunded()
            && ! in_array($this->status, [ParcelReturnStatus::Rejected, ParcelReturnStatus::Cancelled], true);
    }

    public function faultLabel(): string
    {
        return match ($this->fault_party) {
            self::FAULT_SELLER => 'Seller (wrong, defective or not as described)',
            self::FAULT_LOGISTICS => 'Logistics (damaged in transit)',
            self::FAULT_PLATFORM => 'Zefanya (platform-funded exception refund)',
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

    public function refundHistory(): HasMany
    {
        return $this->events()->where(fn ($query) => $query->where('event_key', 'like', 'refund_recorded%')
            ->orWhere('event_key', 'like', 'refund_voided%'));
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
            && $this->refunded_at === null
            && (! self::refundPolicyAvailable() || $this->currentRefundAuthorization() === null);
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

    public function awaitingStationReceipt(): bool
    {
        return $this->isBuyerRequest() && $this->status === ParcelReturnStatus::Requested
            && $this->collectedFromBuyer() && ! $this->hasStationReceipt();
    }

    public function hasStationReceipt(): bool
    {
        return $this->relationLoaded('events')
            ? $this->events->contains('event_key', 'station_received')
            : $this->events()->where('event_key', 'station_received')->exists();
    }

    public function collectionStatusLabel(): string
    {
        return match (true) {
            $this->awaitingStationReceipt() => 'Collected from Buyer · Awaiting station hand-in',
            $this->status !== ParcelReturnStatus::Requested => $this->status->label(),
            $this->hasStationReceipt() => 'Received by Logistics station',
            (int) $this->collection_failed_attempts > 0 && $this->collection_courier_id === null => 'Collection failed',
            default => 'Assigned return pickup',
        };
    }

    /** Stage shown to Logistics, so an undecided or already-collected return is not labelled just "Return requested". */
    public function logisticsStageLabel(): string
    {
        return match (true) {
            $this->awaitingSellerDecision() => 'Awaiting seller decision',
            $this->awaitingBuyerCollection() => $this->collection_courier_id ? 'Approved · pickup rider assigned' : 'Approved · needs a pickup rider',
            $this->isBuyerRequest() && $this->status === ParcelReturnStatus::Requested => $this->collectionStatusLabel(),
            default => $this->status->label(),
        };
    }

    public function scopeCollectionWorkForRider(Builder $query, int $riderId): Builder
    {
        return $query->where(fn ($work) => $work->where('status', ParcelReturnStatus::Requested->value)
            ->orWhereNotNull('collected_at'))
            ->where(fn ($assigned) => $assigned
                ->whereHas('collectionCourier', fn ($courier) => $courier->where('rider_id', $riderId))
                ->orWhere(fn ($released) => $released->whereNull('collection_courier_id')
                    ->whereHas('events', fn ($events) => $events->where('event_key', 'like', 'collection_failed%')
                        ->where('actor_type', 'rider')->where('actor_id', $riderId))))
            ->with('events')->orderByRaw('CASE WHEN collected_at IS NULL THEN 0 ELSE 1 END')
            ->latest('collection_assigned_at')->limit(50);
    }

    /** Why this return cannot be moved through the network yet; null when it can. */
    public function movementBlockedReason(): ?string
    {
        return match (true) {
            $this->awaitingSellerDecision() => 'The Seller has not decided this buyer return request yet.',
            $this->awaitingBuyerCollection() => 'This parcel is still with the buyer. A rider must collect it first.',
            $this->awaitingStationReceipt() => 'The station must acknowledge physical receipt from the rider before reverse transport.',
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
        // Zefanya funds the refund: Seller earnings, settlement and commission are left as they are.
        if ($this->isPlatformFunded()) {
            return false;
        }

        return in_array($this->seller_decision, [self::APPROVED, self::AUTO_APPROVED], true)
            || $this->dispute_outcome === self::DISPUTE_OVERTURNED
            || $this->refunded_at !== null;
    }

    /**
     * A seller rejection the buyer has not yet asked an admin to review, on a
     * shipment that is still delivered and uncompleted. A completed shipment
     * cannot become Returned, so an overturn there could never finish.
     */
    public function canBuyerEscalate(): bool
    {
        return $this->status === ParcelReturnStatus::Rejected
            && $this->seller_decision === self::REJECTED
            && $this->escalated_at === null
            && $this->sellerOrder?->status === OrderStatus::Delivered;
    }

    /**
     * Established refund obligations not yet paid: an approved return (or one without a Seller
     * decision step), or an authorized exception refund later voided, with refundable cash.
     * Pending, rejected and upheld requests are not counted merely because they exist.
     */
    public function scopeAwaitingRefundRecording(Builder $query): Builder
    {
        return $query->where('parcel_returns.financial_review_required', true)->whereNull('parcel_returns.refunded_at')
            ->where('parcel_returns.status', '!=', ParcelReturnStatus::Cancelled->value)
            ->where(fn ($owed) => $owed->whereNull('parcel_returns.seller_response_due_at')
                ->orWhereIn('parcel_returns.seller_decision', [self::APPROVED, self::AUTO_APPROVED])
                ->orWhere('parcel_returns.dispute_outcome', self::DISPUTE_OVERTURNED)
                ->orWhereHas('events', fn ($event) => $event->where('event_key', 'like', 'refund_recorded%')->whereNotNull('metadata->exception'))
                ->orWhereHas('events', fn ($event) => $event->where('event_key', 'like', 'refund_authorized%')->whereNotNull('metadata->exception_basis')))
            ->whereHas('sellerOrder.payment', fn ($payment) => $payment->where('status', PaymentStatus::Paid->value)
                ->where('amount_minor', '>', 0)
                ->where(fn ($cash) => $cash->whereNull('collected_amount_minor')->orWhere('collected_amount_minor', '>', 0)));
    }

    public function scopeVisibleToProvider(Builder $query, int $providerId): Builder
    {
        return $query->where(fn ($visible) => $visible->where('origin_provider_id', $providerId)
            ->orWhereHas('sellerOrder', fn ($parcel) => $parcel->heldBy($providerId))
            ->orWhereHas('sellerOrder.parcelHandoffs', fn ($handoff) => $handoff->where('direction', 'return')->where('origin_provider_id', $providerId)));
    }
}
