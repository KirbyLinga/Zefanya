<?php

namespace App\Services\Logistics;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus as State;
use App\Exceptions\StaleStateException;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Logistics\InventoryException;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelHandoffException;
use App\Models\Logistics\ParcelReturn;
use App\Models\Logistics\ParcelReturnEvent;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Notifications\Buyer\ReturnDisputeResolved;
use App\Notifications\Buyer\ReturnRequestDecided;
use App\Notifications\Rider\BuyerReturnCollection;
use App\Notifications\Rider\DeliveryReturned;
use App\Notifications\Seller\BuyerReturnRequested;
use App\Notifications\Seller\ReturnDisputed;
use App\Services\Buyer\AutomaticBuyerReceiptService;
use App\Services\Rider\RiderNotifier;
use App\Services\Seller\SellerOrderStatusService;
use App\Services\Seller\SellerSettlementService;
use App\Services\Seller\StockService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParcelReturnService
{
    public const BUYER_REASONS = ['Item arrived damaged', 'Wrong item received', 'Item is defective', 'Missing items or parts', 'Item does not match the description', 'Other'];

    public const REVERSE_ROUTE_AUTHORIZED = 'reverse_route_authorized';

    public function __construct(private readonly ParcelHandoffVerification $verification = new ParcelHandoffVerification) {}

    /** The same persisted delivery clock used by automatic receipt completion. */
    public function buyerReturnDeadline(SellerOrder $parcel): ?CarbonInterface
    {
        if ($parcel->status !== OrderStatus::Delivered) {
            return null;
        }
        $parcel->loadMissing('statusHistories');
        $deliveries = $parcel->statusHistories->where('to_status', OrderStatus::Delivered);
        if ($deliveries->isEmpty() || $deliveries->contains(fn ($history) => $history->created_at === null)) {
            return null;
        }
        $deliveredAt = $deliveries->max('created_at');
        if ($deliveredAt->isFuture()) {
            return null;
        }

        return $deliveredAt->copy()->addHours(AutomaticBuyerReceiptService::WAIT_HOURS);
    }

    public function canBuyerRequest(SellerOrder $parcel): bool
    {
        $deadline = $this->buyerReturnDeadline($parcel);

        return $deadline !== null && now()->lt($deadline) && $parcel->parcelReturn === null;
    }

    /**
     * Buyer intake reuses the existing return record and physical receipt safeguards.
     *
     * @param  list<UploadedFile>  $photos
     */
    public function requestByBuyer(SellerOrder $parcel, Buyer $buyer, string $reason, ?string $description = null, array $photos = []): ParcelReturn
    {
        return DB::transaction(function () use ($parcel, $buyer, $reason, $description, $photos): ParcelReturn {
            // Completion and return intake must acquire this same shipment lock.
            $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $parcel->order->buyer_id === (int) $buyer->id, 404);
            ParcelIncidentService::assertOperationsAllowed($parcel);
            $this->require(filled(trim($reason)) && mb_strlen($reason) <= 1000, 'reason', 'Provide a return reason of 1000 characters or fewer.');
            $this->conflict($parcel->status === OrderStatus::Delivered, 'return', 'Only a delivered, uncompleted shipment can be returned.');
            $existing = $parcel->parcelReturn;
            if ($existing !== null) {
                return $existing;
            }
            $this->require($this->canBuyerRequest($parcel), 'return', 'The seven-day return window has closed or the delivery timestamp requires verification.');
            $origin = $parcel->originStation;
            $record = new ParcelReturn;
            $record->forceFill([
                'seller_order_id' => $parcel->id, 'status' => State::Requested,
                'delivery_attempts' => (int) $parcel->delivery_attempts,
                'reason' => trim($reason), 'requested_at' => now(),
                'description' => filled($description) ? trim($description) : null,
                'photos' => array_map(fn (UploadedFile $photo): string => $photo->store('return-photos', 'local'), $photos),
                'seller_response_due_at' => now()->addDays(ParcelReturn::SELLER_DECISION_DAYS),
                'origin_provider_id' => $parcel->carrier_provider_id ?? $origin?->logistics_provider_id ?? $parcel->courier?->logistics_provider_id,
                'origin_hub_id' => $parcel->origin_station_hub_id,
                'seller_address' => $parcel->pickup_address_snapshot,
                'financial_review_required' => $parcel->payment !== null && $parcel->payment->status->value !== 'cancelled',
            ])->save();
            $this->event($record, 'requested', 'buyer', (int) $buyer->id);
            if ($record->origin_provider_id === null || $record->origin_hub_id === null
                || (int) $origin?->logistics_provider_id !== (int) $record->origin_provider_id
                || blank($record->seller_address['full'] ?? null)) {
                $record->forceFill(['exception_reason' => 'The original pickup location requires verification before reverse routing.'])->save();
            }
            // Database-channel notification participates in the same transaction.
            $parcel->seller?->notify(new BuyerReturnRequested($parcel, $record));

            return $record;
        }, 3);
    }

    /** The Seller accepts: the return may now enter the existing physical flow. Pending earnings and commission leave; no refund is inferred. */
    public function approveBySeller(ParcelReturn $record, Seller $seller): void
    {
        DB::transaction(function () use ($record, $seller): void {
            $record = $this->lockedForDecision($record, $seller);
            $record->forceFill(['seller_decision' => ParcelReturn::APPROVED, 'seller_decided_at' => now()] + $this->faultDefaults($record))->save();
            $this->event($record, 'seller_approved', 'seller', (int) $seller->id);
            app(SellerSettlementService::class)->releasePendingForReturn($record->sellerOrder);
            $record->sellerOrder->order?->buyer?->notify(new ReturnRequestDecided($record));
        }, 3);
    }

    /** The Seller refuses with a reason: the request closes and the order can complete again. */
    public function rejectBySeller(ParcelReturn $record, Seller $seller, string $reason): void
    {
        $reason = trim($reason);
        $this->require(mb_strlen($reason) >= 10 && mb_strlen($reason) <= 300, 'reason', 'The rejection reason must be 10 to 300 characters.');

        DB::transaction(function () use ($record, $seller, $reason): void {
            $record = $this->lockedForDecision($record, $seller);
            $record->forceFill(['seller_decision' => ParcelReturn::REJECTED, 'seller_decision_reason' => $reason, 'seller_decided_at' => now()])->save();
            $this->setState($record, State::Rejected, 'seller_rejected', 'seller', (int) $seller->id);
            $this->clearException($record, 'The Seller rejected the buyer return request.');
            $record->sellerOrder->order?->buyer?->notify(new ReturnRequestDecided($record));
        }, 3);
    }

    /**
     * The buyer may ask an admin to review a rejection, once. The request
     * becomes In Dispute and the Seller cannot act on it until that review.
     */
    public function escalateByBuyer(ParcelReturn $record, Buyer $buyer, string $reason): void
    {
        $reason = trim($reason);
        $this->require(mb_strlen($reason) >= 10 && mb_strlen($reason) <= 1000, 'reason', 'Tell the admin why this rejection should be reviewed, in 10 to 1000 characters.');

        DB::transaction(function () use ($record, $buyer, $reason): void {
            $record = $this->locked($record);
            abort_unless((int) $record->sellerOrder->order->buyer_id === (int) $buyer->id, 404);
            $this->conflict($record->canBuyerEscalate(), 'return', 'This rejection can no longer be escalated.');
            $record->forceFill(['escalated_at' => now(), 'status' => State::InDispute])->save();
            $this->event($record, 'buyer_escalated', 'buyer', (int) $buyer->id, ['reason' => $reason]);
            $record->sellerOrder->seller?->notify(new ReturnDisputed($record));
        }, 3);
    }

    /** An admin ends the dispute: the rejection stands, or the return may proceed. */
    public function resolveDispute(ParcelReturn $record, Admin $admin, string $outcome, string $note): void
    {
        $this->admin($admin);
        $note = trim($note);
        $this->require(in_array($outcome, [ParcelReturn::DISPUTE_UPHELD, ParcelReturn::DISPUTE_OVERTURNED], true), 'outcome', 'Choose whether the rejection stands or the return goes ahead.');
        $this->require(mb_strlen($note) >= 10 && mb_strlen($note) <= 1000, 'note', 'The resolution note must be 10 to 1000 characters.');

        DB::transaction(function () use ($record, $admin, $outcome, $note): void {
            $record = $this->locked($record, investigation: true);
            $this->conflict($record->inDispute(), 'return', 'Only a return that is in dispute can be resolved.');
            $record->forceFill([
                'status' => $outcome === ParcelReturn::DISPUTE_OVERTURNED ? State::Requested : State::Rejected,
                'dispute_outcome' => $outcome,
                'dispute_note' => $note,
                'dispute_resolved_at' => now(),
            ] + ($outcome === ParcelReturn::DISPUTE_OVERTURNED ? $this->faultDefaults($record) : []))->save();
            $this->event($record, 'dispute_'.$outcome, 'admin', (int) $admin->id, ['note' => $note]);
            if ($outcome === ParcelReturn::DISPUTE_OVERTURNED) {
                app(SellerSettlementService::class)->releasePendingForReturn($record->sellerOrder);
            }
            $record->sellerOrder->loadMissing(['order.buyer', 'seller']);
            $record->sellerOrder->order?->buyer?->notify(new ReturnDisputeResolved($record));
            $record->sellerOrder->seller?->notify(new ReturnDisputeResolved($record));
        }, 3);
    }

    /**
     * A recorded COD refund takes the same pending earnings and commission out.
     * It keeps what was paid, to whom, when, and the proof, so the cash can be reconciled.
     */
    public function recordRefund(ParcelReturn $record, Admin $admin, string $reference, int $amountMinor, string $recipient, string $paidOn, ?string $proofPath = null, ?string $reason = null): void
    {
        $this->admin($admin);
        $reference = trim($reference);
        $recipient = trim($recipient);
        $reason = trim((string) $reason);
        $this->require($reference !== '' && mb_strlen($reference) <= 255, 'reference', 'A refund reference of at most 255 characters is required.');
        $this->require($recipient !== '' && mb_strlen($recipient) <= 255, 'recipient', 'Say who received the refund, in at most 255 characters.');
        $this->require($amountMinor > 0, 'amount', 'Enter the amount that was refunded.');
        $this->require(! Carbon::parse($paidOn)->isFuture(), 'paid_on', 'The refund date cannot be in the future.');

        DB::transaction(function () use ($record, $admin, $reference, $amountMinor, $recipient, $paidOn, $proofPath, $reason): void {
            $record = $this->locked($record, investigation: true);
            $this->require($record->financial_review_required, 'refund', 'This return has no payment to refund.');
            $this->conflict($record->refunded_at === null, 'refund', 'This return already has a recorded refund.');
            $this->require(! $record->needsAdminFaultDecision(), 'refund', 'The buyer gave "Other" as the reason. Decide who pays (Seller or Logistics) before recording the refund.');
            $collected = (int) $record->sellerOrder->payment()->value('amount_minor');
            $this->require($amountMinor <= $collected, 'amount', 'The refund cannot be more than the buyer paid (₱'.number_format($collected / 100, 2).').');
            $this->require($amountMinor === $collected || (mb_strlen($reason) >= 10 && mb_strlen($reason) <= 1000), 'refund_reason',
                'This refund is less than the buyer paid (₱'.number_format($collected / 100, 2).'). Explain why in 10 to 1000 characters.');
            $record->forceFill($this->faultDefaults($record) + [
                'refunded_at' => now(),
                'refund_reference' => $reference,
                'refund_amount_minor' => $amountMinor,
                'refund_recipient' => $recipient,
                'refund_paid_on' => $paidOn,
                'refund_proof_path' => $proofPath,
                'refund_reason' => $amountMinor === $collected ? null : $reason,
            ])->save();
            $this->event($record, $this->numberedKey($record, 'refund_recorded'), 'admin', (int) $admin->id, ['reference' => $reference, 'amount_minor' => $amountMinor, 'recipient' => $recipient, 'paid_on' => $paidOn, 'has_proof' => $proofPath !== null, 'reason' => $amountMinor === $collected ? null : $reason]);
            app(SellerSettlementService::class)->releasePendingForReturn($record->sellerOrder);
            app(ReturnChargeService::class)->sync($record);
        }, 3);
    }

    /**
     * The recorded refund never reached the buyer (failed or bounced transfer). Voiding keeps
     * the original in the return history and audit log, and reopens the refund so Admin can
     * record a new one. The refund is still owed, so who pays stays as decided.
     */
    public function voidRefund(ParcelReturn $record, Admin $admin, string $reason): void
    {
        $this->admin($admin);
        $reason = trim($reason);
        $this->require(mb_strlen($reason) >= 10 && mb_strlen($reason) <= 1000, 'void_reason', 'Explain why the refund failed in 10 to 1000 characters.');

        DB::transaction(function () use ($record, $admin, $reason): void {
            $record = $this->locked($record, investigation: true);
            $this->conflict($record->refunded_at !== null, 'void_reason', 'This return has no recorded refund to void.');
            $voided = [
                'reference' => $record->refund_reference, 'amount_minor' => $record->refund_amount_minor,
                'recipient' => $record->refund_recipient, 'paid_on' => $record->refund_paid_on?->toDateString(),
                'proof_path' => $record->refund_proof_path, 'refund_reason' => $record->refund_reason, 'void_reason' => $reason,
            ];
            $record->forceFill(['refunded_at' => null, 'refund_reference' => null, 'refund_amount_minor' => null, 'refund_recipient' => null,
                'refund_paid_on' => null, 'refund_proof_path' => null, 'refund_reason' => null])->save();
            $this->event($record, $this->numberedKey($record, 'refund_voided'), 'admin', (int) $admin->id, $voided);
            OperationsAuditLog::record($admin, 'return_refund_voided', $record, 'Return #'.$record->id, $voided, ['refunded' => false], $reason);
            app(SellerSettlementService::class)->releasePendingForReturn($record->sellerOrder);
            app(ReturnChargeService::class)->sync($record);
        }, 3);
    }

    /** Refunds can be recorded and voided more than once; each event keeps its own history row. */
    private function numberedKey(ParcelReturn $record, string $key): string
    {
        $count = $record->events()->where(fn ($query) => $query->where('event_key', $key)->orWhere('event_key', 'like', $key.'_%'))->count();

        return $count === 0 ? $key : $key.'_'.($count + 1);
    }

    /**
     * An admin decides who pays for the return: the Seller (the default once
     * approved) or Logistics (damaged in transit). Logistics fault leaves the
     * Seller's earnings whole. It can change until the refund is recorded.
     */
    public function setFault(ParcelReturn $record, Admin $admin, string $party, string $note): void
    {
        $this->admin($admin);
        $note = trim($note);
        $this->require(in_array($party, [ParcelReturn::FAULT_SELLER, ParcelReturn::FAULT_LOGISTICS], true), 'fault_party', 'Choose who pays: the Seller or Logistics.');
        $this->require(mb_strlen($note) >= 10 && mb_strlen($note) <= 1000, 'fault_note', 'Explain the decision in 10 to 1000 characters.');

        DB::transaction(function () use ($record, $admin, $party, $note): void {
            $record = $this->locked($record, investigation: true);
            $this->conflict($record->faultCanChange(), 'fault_party', 'Who pays can no longer change: the refund is recorded or the request was rejected.');
            $from = $record->fault_party;
            $record->forceFill(['fault_party' => $party, 'fault_note' => $note, 'fault_set_by_admin_id' => $admin->id, 'fault_set_at' => now()] + $this->faultDefaults($record))->save();
            OperationsAuditLog::record($admin, 'return_fault_set', $record, 'Return #'.$record->id, ['fault_party' => $from], ['fault_party' => $party], $note);
            app(SellerSettlementService::class)->releasePendingForReturn($record->sellerOrder);
            app(ReturnChargeService::class)->sync($record);
        }, 3);
    }

    /**
     * Once a return is approved, the Seller pays unless an admin decided otherwise. The
     * return trip is frozen at the original delivery fee: same route, weight and carrier,
     * at the checkout rate. Only a buyer's own request has a return trip to charge.
     *
     * @return array{fault_party: string, return_shipping_fee_minor: ?int}
     */
    private function faultDefaults(ParcelReturn $record): array
    {
        return [
            'fault_party' => $record->fault_party ?? ParcelReturn::FAULT_SELLER,
            'return_shipping_fee_minor' => $record->return_shipping_fee_minor
                ?? ($record->isBuyerRequest() ? (int) $record->sellerOrder->shipping_fee_minor : null),
        ];
    }

    /**
     * A Seller who misses the deadline cannot leave the buyer and the order
     * stuck, so the request is approved for them. Returns false when the
     * Seller answered first or the request no longer qualifies.
     */
    public function autoApproveOverdue(int $recordId): bool
    {
        return DB::transaction(function () use ($recordId): bool {
            $record = ParcelReturn::query()->findOrFail($recordId);
            $record = $this->locked($record);

            if (! $record->sellerDecisionOverdue()) {
                return false;
            }

            $record->forceFill(['seller_decision' => ParcelReturn::AUTO_APPROVED, 'seller_decided_at' => now()] + $this->faultDefaults($record))->save();
            $this->event($record, 'seller_approved', 'system', null);
            app(SellerSettlementService::class)->releasePendingForReturn($record->sellerOrder);
            $record->sellerOrder->order?->buyer?->notify(new ReturnRequestDecided($record));

            return true;
        }, 3);
    }

    /**
     * The provider holding the parcel sends one of its riders to the buyer.
     * Reassigning is allowed until the parcel is collected.
     */
    public function assignBuyerCollection(ParcelReturn $record, LogisticsProvider $provider, int $courierId): void
    {
        $courier = DB::transaction(function () use ($record, $provider, $courierId): Courier {
            $record = $this->locked($record);
            $this->owner($record->sellerOrder, $provider);
            $this->conflict($record->awaitingBuyerCollection(), 'return', 'This return is not waiting to be collected from a buyer.');
            $courier = Courier::query()->whereKey($courierId)->where('logistics_provider_id', $provider->id)->with('rider')->first();
            $this->require($courier?->rider !== null && in_array($courier->rider->status, Rider::ASSIGNABLE_STATUSES, true), 'courier_id', 'Choose an active rider from your own fleet.');
            $record->forceFill(['collection_courier_id' => $courier->id, 'collection_assigned_at' => now()])->save();
            $this->event($record, 'collection_assigned', 'logistics', (int) $provider->id, ['courier_id' => $courier->id]);

            return $courier;
        }, 3);

        app(RiderNotifier::class)->send($courier->rider, new BuyerReturnCollection($record->fresh(['sellerOrder'])));
    }

    /**
     * The assigned rider has the parcel in hand. The waybill must match, and the
     * rider's station takes custody, so the existing reverse flow starts from there.
     */
    public function confirmBuyerCollection(ParcelReturn $record, Rider $rider, string $tracking): void
    {
        DB::transaction(function () use ($record, $rider, $tracking): void {
            $record = $this->locked($record);
            $courier = $record->collectionCourier;
            abort_unless($courier !== null && (int) $courier->rider_id === (int) $rider->id && in_array($rider->status, Rider::ASSIGNABLE_STATUSES, true), 403);
            $this->conflict($record->awaitingBuyerCollection(), 'return', 'This parcel has already been collected.');
            $this->require(filled($tracking) && hash_equals((string) $record->sellerOrder->tracking_number, trim($tracking)), 'tracking_number', 'Enter the tracking number on this parcel.');
            $this->require($rider->station_hub_id !== null, 'return', 'Your account has no station to bring this parcel to. Contact Logistics.');
            $record->forceFill(['collected_at' => now()])->save();
            $record->sellerOrder->forceFill(['custody_provider_id' => $courier->logistics_provider_id, 'custody_hub_id' => $rider->station_hub_id])->save();
            $this->event($record, 'collected_from_buyer', 'rider', (int) $rider->id);
        }, 3);
    }

    private function lockedForDecision(ParcelReturn $record, Seller $seller): ParcelReturn
    {
        $record = $this->locked($record);
        abort_unless($seller->isApproved() && (int) $record->sellerOrder->seller_id === (int) $seller->id, 403);
        $this->conflict(! $record->inDispute(), 'return', 'This return is in dispute. An admin has to resolve it.');
        $this->conflict($record->awaitingSellerDecision(), 'return', 'This return request has already been decided.');
        $this->conflict(! $record->sellerDecisionOverdue(), 'return', 'The 3-day deadline has passed, so this request is being approved automatically.');

        return $record;
    }

    public function request(SellerOrder $parcel, LogisticsProvider $provider, string $reason): ParcelReturn
    {
        return DB::transaction(function () use ($parcel, $provider, $reason): ParcelReturn {
            $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($parcel);
            $this->owner($parcel, $provider);
            $this->require(filled(trim($reason)) && mb_strlen($reason) <= 1000, 'reason', 'Provide a return reason of 1000 characters or fewer.');

            return $this->create($parcel, 'logistics', (int) $provider->id, trim($reason));
        }, 3);
    }

    /**
     * Admin incident resolution authorizes the existing physical return flow.
     * Its original delivery, custody, and provider preconditions still apply.
     */
    public function requestForIncident(SellerOrder $parcel, Admin $admin, string $reason): ParcelReturn
    {
        $this->admin($admin);

        return DB::transaction(function () use ($parcel, $admin, $reason): ParcelReturn {
            $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($parcel);
            $this->require(filled(trim($reason)) && mb_strlen($reason) <= 1000, 'return', 'A return reason of 1000 characters or fewer is required.');
            $providerId = $this->custodyProviderId($parcel);
            $provider = $providerId !== null ? LogisticsProvider::query()->find($providerId) : null;
            $this->require($provider !== null, 'return', 'A responsible Logistics provider is required for the existing return workflow.');
            $this->owner($parcel, $provider);

            return $this->create($parcel, 'admin', (int) $admin->id, trim($reason));
        });
    }

    /** Called by the canonical engine inside its locked second-failure transaction. */
    public function ensureAfterSecondFailure(SellerOrder $parcel, ?OrderActor $actor, ?int $actorId): void
    {
        DB::transaction(function () use ($parcel, $actor, $actorId): void {
            $parcel = SellerOrder::query()->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            ParcelIncidentService::assertOperationsAllowed($parcel);
            if ($parcel->status === OrderStatus::DeliveryFailed && (int) $parcel->delivery_attempts >= 2) {
                $this->create($parcel, $actor?->value ?? 'system', $actorId,
                    'Two failed delivery attempts. '.($parcel->delivery_failure_reason ?? 'Review recorded failure evidence.'));
            }
        });
    }

    private function create(SellerOrder $parcel, string $actor, ?int $actorId, string $reason): ParcelReturn
    {
        $this->require($parcel->status === OrderStatus::DeliveryFailed && (int) $parcel->delivery_attempts >= 1, 'return', 'Only a failed delivery can enter the physical return workflow.');
        $existing = ParcelReturn::query()->where('seller_order_id', $parcel->id)->first();
        if ($existing) {
            return $existing;
        }
        $origin = $parcel->originStation;
        $record = new ParcelReturn;
        $record->forceFill([
            'seller_order_id' => $parcel->id, 'status' => State::Requested,
            'delivery_attempts' => (int) $parcel->delivery_attempts,
            'reason' => $reason, 'requested_at' => now(),
            'origin_provider_id' => $parcel->carrier_provider_id ?? $origin?->logistics_provider_id ?? $parcel->courier?->logistics_provider_id,
            'origin_hub_id' => $parcel->origin_station_hub_id,
            'seller_address' => $parcel->pickup_address_snapshot,
            'financial_review_required' => $parcel->payment !== null && $parcel->payment->status->value !== 'cancelled',
        ])->save();
        $this->event($record, 'requested', $actor, $actorId);
        DB::afterCommit(fn () => app(RiderNotifier::class)->send($parcel->deliveryCourier?->rider, new DeliveryReturned($parcel->fresh(), (int) $parcel->delivery_attempts, true)));
        if ($record->origin_provider_id === null || $record->origin_hub_id === null || (int) $origin?->logistics_provider_id !== (int) $record->origin_provider_id || blank($record->seller_address['full'] ?? null)) {
            $this->exception($record, 'The original pickup location is not fully recorded. Admin must verify it from historical evidence before reverse routing.');
        }

        return $record->fresh();
    }

    public function custodyProviderId(SellerOrder $parcel): ?int
    {
        return $parcel->currentCustodyProviderId() ?? $parcel->deliveryCourier?->logistics_provider_id ?? $parcel->courier?->logistics_provider_id;
    }

    public function owner(SellerOrder $parcel, LogisticsProvider $provider): void
    {
        abort_unless($this->custodyProviderId($parcel) === (int) $provider->id, 403);
        abort_unless($provider->isApproved() && $provider->archived_at === null, 403);
    }

    private function locked(ParcelReturn $record, bool $investigation = false): ParcelReturn
    {
        $parcel = SellerOrder::query()->whereKey($record->seller_order_id)->lockForUpdate()->firstOrFail();
        if (! $investigation) {
            ParcelIncidentService::assertOperationsAllowed($parcel);
        }

        return ParcelReturn::query()->whereKey($record->id)->with('sellerOrder')->lockForUpdate()->firstOrFail();
    }

    public function exception(ParcelReturn $record, string $reason): void
    {
        $record->forceFill(['exception_reason' => $reason])->save();
        $providerId = $this->custodyProviderId($record->sellerOrder);
        if ($providerId !== null) {
            $exception = ParcelHandoffException::query()->where('seller_order_id', $record->seller_order_id)->where('status', 'OPEN')->first() ?? new ParcelHandoffException;
            $exception->forceFill(['seller_order_id' => $record->seller_order_id, 'origin_provider_id' => $providerId,
                'status' => 'OPEN', 'reason' => 'Physical return: '.$reason])->save();
        }
    }

    private function clearException(ParcelReturn $record, string $note, ?int $adminId = null): void
    {
        $record->forceFill(['exception_reason' => null])->save();
        ParcelHandoffException::query()->where('seller_order_id', $record->seller_order_id)->where('status', 'OPEN')
            ->update(['status' => 'RESOLVED', 'resolved_at' => now(), 'resolved_by' => $adminId, 'resolution_note' => $note]);
    }

    public function verifyOrigin(Admin $admin, ParcelReturn $record, int $hubId, string $barangay, string $address, string $evidence): void
    {
        $this->admin($admin);
        DB::transaction(function () use ($admin, $record, $hubId, $barangay, $address, $evidence): void {
            $record = $this->locked($record, investigation: true);
            $this->require($record->status === State::Requested, 'return', 'Verify the original location before reverse transport starts.');
            $this->require(filled(trim($evidence)) && filled(trim($address)), 'evidence', 'Historical pickup address and review evidence are required.');
            $hub = LogisticsHub::query()->whereKey($hubId)->operational()->firstOrFail();
            $this->require($record->origin_provider_id === null || (int) $record->origin_provider_id === (int) $hub->logistics_provider_id, 'hub', 'The original carrier cannot be changed.');
            $this->require($record->origin_hub_id === null || (int) $record->origin_hub_id === $hubId, 'hub', 'The frozen origin station cannot be changed.');
            $area = (new LogisticsHubService)->areaForBarangay((int) $hub->logistics_provider_id, $barangay);
            $this->require($area !== null && (int) $area->station_hub_id === $hubId, 'barangay_code', 'The verified origin barangay must belong to the original station coverage.');
            $this->require(blank($record->seller_address['full'] ?? null), 'return', 'The original pickup snapshot is already frozen.');
            $record->forceFill(['origin_provider_id' => $hub->logistics_provider_id, 'origin_hub_id' => $hubId,
                'seller_address' => ['full' => trim($address), 'barangay_code' => $barangay, 'province_code' => $hub->province_code], 'exception_reason' => null])->save();
            $this->event($record, 'origin_verified', 'admin', $admin->id, ['evidence' => $evidence]);
            $this->clearException($record, 'Original pickup location verified from historical evidence.', $admin->id);
        });
    }

    /**
     * Admin override for one parcel: with no usable reverse partnership, it may
     * still go back to the provider that handed it over. No partnership is created,
     * and acceptance, scans and custody rules still apply.
     */
    public function authorizeReverseRoute(Admin $admin, ParcelReturn $record, string $note): void
    {
        $this->admin($admin);
        DB::transaction(function () use ($admin, $record, $note): void {
            $record = $this->locked($record, investigation: true);
            $this->require(in_array($record->status, [State::Requested, State::InTransit], true), 'return', 'Reverse routing can only be authorized before the parcel reaches the origin.');
            $this->require(
                $record->sellerOrder->parcelHandoffs()->where('direction', 'forward')->where('status', 'RECEIVED')
                    ->where('destination_provider_id', $this->custodyProviderId($record->sellerOrder))->exists(),
                'return',
                'This parcel did not arrive through a verified cross-provider handoff.',
            );
            $this->event($record, self::REVERSE_ROUTE_AUTHORIZED, 'admin', $admin->id, ['note' => trim($note)]);
            $this->clearException($record, 'Admin authorized the reverse route for this parcel: '.trim($note), $admin->id);
        });
    }

    public function reverseRouteAuthorized(ParcelReturn $record): bool
    {
        return $record->events()->where('event_key', self::REVERSE_ROUTE_AUTHORIZED)->exists();
    }

    public function reverseManifest(ParcelReturn $record, LogisticsProvider $provider, int $destinationHubId): Manifest
    {
        return DB::transaction(function () use ($record, $provider, $destinationHubId) {
            $record = $this->locked($record);
            $this->owner($record->sellerOrder, $provider);
            $this->routeReady($record);
            $origin = (new ManifestService)->currentHubId($record->sellerOrder);
            $this->require($origin !== null, 'return', 'Current hub custody requires verification.');
            $service = new ManifestService;
            $manifest = $service->create($provider, $origin, $destinationHubId, 'return');
            $service->addParcel($manifest, $record->sellerOrder);

            return $manifest;
        }, 3);
    }

    public function reverseHandoff(ParcelReturn $record, LogisticsProvider $provider): ParcelHandoff
    {
        $this->owner($record->sellerOrder, $provider);
        ParcelIncidentService::assertOperationsAllowed($record->sellerOrder);
        $this->routeReady($record);
        $this->require((int) $record->origin_provider_id !== (int) $provider->id, 'return', 'Use internal transport inside the original provider network.');
        try {
            $handoff = (new ParcelHandoffService)->request($record->sellerOrder, $provider);
            $this->clearException($record, 'Authorized reverse route restored.');

            return $handoff;
        } catch (ValidationException $e) {
            $this->exception($record, 'No authorized directed reverse route covers the original Seller location. Approve the reverse provider link and retry.');
            throw $e;
        }
    }

    private function routeReady(ParcelReturn $record): void
    {
        $this->require($record->movementBlockedReason() === null, 'return', (string) $record->movementBlockedReason());
        $this->require(in_array($record->status, [State::Requested, State::InTransit], true)
            && $record->origin_hub_id !== null && $record->origin_provider_id !== null && filled($record->seller_address['full'] ?? null),
            'return', 'Resolve the original pickup location before routing this return.');
    }

    /** A stored, verified movement is required; caller-supplied objects are re-read. */
    public function movement(SellerOrder $parcel, ManifestItem|ParcelHandoff $movement): void
    {
        $movement = $movement->fresh();
        if ($movement === null || (int) $movement->seller_order_id !== (int) $parcel->id) {
            return;
        }
        $container = $movement instanceof ManifestItem ? $movement->manifest : $movement;
        if ($container->direction !== 'return') {
            return;
        }
        $record = $parcel->parcelReturn()->first();
        if ($record === null) {
            return;
        }
        $record = $this->locked($record);
        if ($container->dispatched_at !== null && $record->status === State::Requested) {
            $this->setState($record, State::InTransit, 'in_transit', 'logistics', $container->dispatched_by_id);
        }
        if ($movement->received_at !== null && (new ManifestService)->currentHubId($record->sellerOrder->fresh()) === (int) $record->origin_hub_id
            && $this->custodyProviderId($record->sellerOrder->fresh()) === (int) $record->origin_provider_id
            && in_array($record->status, [State::Requested, State::InTransit], true)) {
            $this->arrived($record, (int) $movement->received_by_id);
        }
    }

    public function scan(ParcelReturn $record, LogisticsProvider $provider, string $tracking, string $phase): array
    {
        $record = $record->fresh(['sellerOrder']);
        $this->atOrigin($record, $provider);
        $this->require(hash_equals((string) $record->sellerOrder->tracking_number, trim($tracking)) && filled($tracking), 'tracking_number', 'Scan the matching return waybill.');
        $origin = $phase === 'origin';
        $this->require($origin ? in_array($record->status, [State::Requested, State::InTransit], true) : in_array($record->status, [State::AtOrigin, State::AwaitingSeller], true), 'return', 'This scan is not available at the current return stage.');
        $action = $origin ? ParcelHandoffVerification::ACTION_RETURN_ORIGIN : ParcelHandoffVerification::ACTION_RETURN_HANDOVER;
        $token = $this->verification->issueForLogistics($provider, $record->sellerOrder, $action, 'manual', (int) $record->origin_hub_id);

        return ['context' => 'physical_return', 'parcel' => ['id' => $record->seller_order_id, 'tracking_number' => $tracking, 'status_label' => $record->status->label()],
            'actions' => [['key' => $action, 'label' => $origin ? 'Confirm return at origin' : 'Prepare Seller handover', 'method' => 'POST',
                'url' => route('logistics.returns.'.($origin ? 'origin' : 'handover'), $record), 'verification_token' => $token,
                'confirm_message' => 'Confirm physical possession at the original station? This is not Seller receipt.']]];
    }

    private function atOrigin(ParcelReturn $record, LogisticsProvider $provider): void
    {
        $this->require($record->movementBlockedReason() === null, 'return', (string) $record->movementBlockedReason());
        ParcelIncidentService::assertOperationsAllowed($record->sellerOrder);
        $this->owner($record->sellerOrder, $provider);
        $this->require((int) $record->origin_provider_id === (int) $provider->id && $record->origin_hub_id !== null && filled($record->seller_address['full'] ?? null), 'return', 'The original return handover location must be verified.');
        $this->require(LogisticsHub::query()->whereKey($record->origin_hub_id)->where('logistics_provider_id', $provider->id)->operational()->exists(), 'hub', 'The origin hub is not operational.');
        $this->require((new ManifestService)->currentHubId($record->sellerOrder) === (int) $record->origin_hub_id
            && ! $record->sellerOrder->manifestItems()->whereNull('released_at')->exists()
            && ! $record->sellerOrder->parcelHandoffs()->whereIn('status', ParcelHandoffService::ACTIVE_STATUSES)->exists(), 'return', 'The parcel must be received at the origin with no active transport.');
    }

    public function confirmOrigin(ParcelReturn $record, LogisticsProvider $provider, string $token): void
    {
        DB::transaction(function () use ($record, $provider, $token): void {
            $record = $this->locked($record);
            $this->atOrigin($record, $provider);
            $this->conflict(in_array($record->status, [State::Requested, State::InTransit], true), 'return', 'Origin receipt is already recorded.');
            $this->verification->assertValidForLogistics($provider, $record->sellerOrder, ParcelHandoffVerification::ACTION_RETURN_ORIGIN, $token, $record->origin_hub_id);
            $record->sellerOrder->forceFill(['custody_provider_id' => $provider->id, 'custody_hub_id' => $record->origin_hub_id])->save();
            $this->arrived($record, $provider->id);
            $this->verification->consumeAfterSuccess(fn () => $this->verification->consumeForLogistics($provider, $record->sellerOrder, ParcelHandoffVerification::ACTION_RETURN_ORIGIN, $token, $record->origin_hub_id));
        });
    }

    private function arrived(ParcelReturn $record, int $actorId): void
    {
        $record->forceFill(['origin_arrived_at' => now(), 'exception_reason' => null])->save();
        $this->setState($record, State::AtOrigin, 'at_origin', 'logistics', $actorId);
        $this->clearException($record, 'Return physically received at the original hub.');
    }

    public function prepareHandover(ParcelReturn $record, LogisticsProvider $provider, string $token): string
    {
        return DB::transaction(function () use ($record, $provider, $token): string {
            $record = $this->locked($record);
            $this->atOrigin($record, $provider);
            $this->require(in_array($record->status, [State::AtOrigin, State::AwaitingSeller], true), 'return', 'Verify origin arrival first.');
            $this->verification->assertValidForLogistics($provider, $record->sellerOrder, ParcelHandoffVerification::ACTION_RETURN_HANDOVER, $token, $record->origin_hub_id);
            $code = Str::random(16);
            $record->forceFill(['handover_code_hash' => hash('sha256', $code), 'handover_nonce' => Str::random(32), 'handover_expires_at' => now()->addMinutes(10)])->save();
            $this->setState($record, State::AwaitingSeller, 'awaiting_seller', 'logistics', $provider->id);
            $this->verification->consumeAfterSuccess(fn () => $this->verification->consumeForLogistics($provider, $record->sellerOrder, ParcelHandoffVerification::ACTION_RETURN_HANDOVER, $token, $record->origin_hub_id));

            return $code;
        });
    }

    public function verifySeller(ParcelReturn $record, Seller $seller, string $tracking, string $code, string $condition): string
    {
        $record = $record->fresh(['sellerOrder']);
        abort_unless($seller->isApproved() && (int) $record->sellerOrder->seller_id === (int) $seller->id, 403);
        $this->require(! $record->inDispute(), 'return', 'This return is in dispute. An admin has to resolve it.');
        $this->require(in_array($condition, ['resellable', 'damaged', 'pending_review'], true), 'condition', 'Record the condition of the received goods.');
        $this->sellerReady($record);
        $this->require(filled($tracking) && hash_equals((string) $record->sellerOrder->tracking_number, trim($tracking))
            && hash_equals((string) $record->handover_code_hash, hash('sha256', $code)), 'handover_code', 'Tracking and the physical handover code must match.');

        return $this->verification->issueForSellerReturn($seller, $record, $condition, $code);
    }

    private function sellerReady(ParcelReturn $record): void
    {
        $this->require($record->status === State::AwaitingSeller && $record->origin_arrived_at !== null
            && $record->handover_expires_at?->isFuture(), 'return', 'Seller receipt requires verified origin arrival and an unexpired physical handover.');
        $provider = LogisticsProvider::query()->findOrFail($record->origin_provider_id);
        $this->atOrigin($record, $provider);
    }

    public function receiveBySeller(ParcelReturn $record, Seller $seller, string $condition, string $token): void
    {
        DB::transaction(function () use ($record, $seller, $condition, $token): void {
            $record = $this->locked($record);
            abort_unless($seller->isApproved() && (int) $record->sellerOrder->seller_id === (int) $seller->id, 403);
            $this->require(! $record->inDispute(), 'return', 'This return is in dispute. An admin has to resolve it.');
            $this->sellerReady($record);
            $this->verification->assertForSellerReturn($seller, $record, $condition, $token);
            $record->forceFill(['seller_received_at' => now(), 'received_by_seller_id' => $seller->id, 'stock_condition' => $condition])->save();
            $this->setState($record, State::Completed, 'completed', 'seller', $seller->id);

            // Restore returned item quantities exactly once when Seller receipt is verified.
            // Idempotent: stock_restored_at prevents duplicate restoration on replay.
            if ($record->stock_restored_at === null && $this->restoreReturnStock($record->sellerOrder)) {
                $record->forceFill(['stock_restored_at' => now()])->save();
            }

            app(SellerOrderStatusService::class)->transition($record->sellerOrder, OrderStatus::Returned,
                note: 'Physical return received and inspected by the owning Seller.', actor: OrderActor::Seller, actorId: $seller->id, source: 'web');
            // The trip back is done: settle who pays Logistics for it.
            app(ReturnChargeService::class)->sync($record->fresh('sellerOrder'));
            $this->verification->consumeAfterSuccess(fn () => $this->verification->assertForSellerReturn($seller, $record, $condition, $token, true));
            $record->forceFill(['handover_code_hash' => null, 'handover_expires_at' => null])->save();
        }, 3);
    }

    /**
     * Restore returned item quantities to shelf via the stock-ledger service,
     * exactly once per seller_order. Returns true when any stock was restored.
     */
    private function restoreReturnStock(SellerOrder $sellerOrder): bool
    {
        $sellerOrder->loadMissing('orderItems');
        $variantIds = $sellerOrder->orderItems->pluck('variant_id')->filter()->unique()->sort()->values();
        if ($variantIds->isEmpty()) {
            return false;
        }

        // Lock all variants in a consistent order to prevent deadlocks.
        $variants = ProductVariant::query()->whereIn('id', $variantIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $allRestored = true;
        $anyRestored = false;
        foreach ($variantIds as $variantId) {
            $variant = $variants->get($variantId);
            if ($variant === null) {
                // Variant permanently missing: record exception and fail restoration.
                $alreadyExcepted = InventoryException::query()->where('seller_order_id', $sellerOrder->id)
                    ->where('variant_id', $variantId)->exists();
                if (! $alreadyExcepted) {
                    InventoryException::create([
                        'seller_order_id' => $sellerOrder->id,
                        'variant_id' => $variantId,
                        'quantity' => 0,
                        'status' => InventoryException::OPEN,
                    ]);
                }
                $allRestored = false;

                continue;
            }
            $totalQty = $sellerOrder->orderItems->where('variant_id', $variantId)->sum('quantity');
            $result = app(StockService::class)->set(
                $variant,
                (int) $variant->stock_quantity + (int) $totalQty,
                'return',
                $sellerOrder->id,
            );
            if ($result !== null) {
                $anyRestored = true;
            } else {
                $allRestored = false;
            }
        }

        if ($anyRestored && $allRestored) {
            Product::syncRollupsForProducts(
                $sellerOrder->orderItems->pluck('product_id')->unique()->values(),
            );
        }

        return $allRestored;
    }

    private function setState(ParcelReturn $record, State $state, string $event, string $actor, ?int $actorId): void
    {
        $record->forceFill(['status' => $state])->save();
        $this->event($record, $event, $actor, $actorId);
    }

    private function event(ParcelReturn $record, string $key, string $actor, ?int $actorId, array $metadata = []): void
    {
        if ($record->events()->where('event_key', $key)->exists()) {
            return;
        }
        $event = new ParcelReturnEvent;
        $event->forceFill(['parcel_return_id' => $record->id, 'event_key' => $key, 'actor_type' => $actor,
            'actor_id' => $actorId, 'occurred_at' => now(), 'metadata' => $metadata])->save();
    }

    public function admin(Admin $admin): void
    {
        abort_unless(in_array($admin->role, ['super_admin', 'admin', 'moderator'], true), 403);
    }

    /** The record moved on since the page rendered: 409 for JSON, same errors for forms. */
    private function conflict(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw StaleStateException::field($field, $message);
        }
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
