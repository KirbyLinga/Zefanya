<?php

namespace App\Services\Logistics;

use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelLossAdjustment;
use App\Models\Logistics\ParcelLossCase;
use App\Models\Logistics\ParcelReturn;
use App\Models\Seller\Seller;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Notifications\Admin\LostParcelFound;
use App\Notifications\Incident\ParcelLostConfirmed;
use App\Services\Seller\SellerOrderStatusService;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lost-parcel resolution.
 *
 * Admin confirming a parcel LOST (the CLOSE_LOST resolution) closes that one
 * parcel, decides which Logistics provider is liable from VERIFIED custody,
 * and records the single compensation the Seller is owed: the saved
 * merchandise value, with no shipping and no commission. Nothing here moves
 * money: the compensation is paid outside the platform and confirmed by an
 * Admin, like every other manual payment. Nothing restores stock, collects
 * COD, earns commission, or reopens a lost order.
 */
class ParcelLossService
{
    /** Parcels reported together per call: keeps one request's transaction bounded. */
    public const MAX_BULK = 100;

    public static function available(): bool
    {
        return RuntimeSchema::hasTable('parcel_loss_cases');
    }

    // ── 1. Finalization ────────────────────────────────────────────────────

    /**
     * Called by ParcelIncidentService inside the resolution transaction, with
     * the parcel and incident rows already locked. A parcel is confirmed lost
     * at most once: a repeat returns the same case and changes nothing.
     */
    public function finalize(SellerOrder $locked, Incidents $incident, Admin $admin, string $reason, ?int $liableProviderId = null): ParcelLossCase
    {
        // A plain read, not a locking one: locking a row that does not exist takes a gap lock on the
        // unique index, and two such transactions then deadlock when each inserts. The parcel row is
        // already locked, and every path that creates a case locks it first, so this cannot race.
        $existing = ParcelLossCase::query()->where('seller_order_id', $locked->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        if ($locked->hasActiveReturn()) {
            throw ValidationException::withMessages(['parcel' => 'This parcel is on an open return. Resolve the return before confirming it lost.']);
        }

        [$providerId, $basis, $hubId] = $this->liability($locked);
        if ($liableProviderId !== null) {
            $chosen = LogisticsProvider::query()->whereKey($liableProviderId)->first();
            if ($chosen === null || ! $chosen->isApproved() || $chosen->archived_at !== null) {
                throw ValidationException::withMessages(['liable_provider_id' => 'Choose an approved Logistics company.']);
            }
            [$providerId, $basis] = [(int) $chosen->id, ParcelLossCase::BASIS_ADMIN];
        }

        // Moves the parcel to LOST first: it refuses a parcel that is not an open, collected one.
        app(SellerOrderStatusService::class)->recordLoss($locked, $admin, $reason);
        $this->closeTransfers($locked, $admin, $reason);

        $case = (new ParcelLossCase)->forceFill([
            'seller_order_id' => $locked->id,
            'seller_id' => $locked->seller_id,
            'incident_id' => $incident->id,
            'confirmed_by_admin_id' => $admin->id,
            'confirmed_at' => now(),
            'reason' => $reason,
            'logistics_provider_id' => $providerId,
            'liability_basis' => $providerId === null ? ParcelLossCase::BASIS_REVIEW : $basis,
            'custody_hub_id' => $hubId,
            // The saved merchandise value: never shipping, never commission.
            'merchandise_minor' => max(0, (int) $locked->subtotal_minor),
            'compensation_status' => $providerId === null ? ParcelLossCase::REVIEW_REQUIRED : ParcelLossCase::PENDING,
        ]);
        $case->save();

        OperationsAuditLog::record($admin, 'parcel_lost_confirmed', $case, 'Parcel '.($locked->tracking_number ?: '#'.$locked->id),
            ['status' => $locked->getOriginal('status') ?? 'open'],
            ['status' => 'LOST', 'liable' => $providerId === null ? 'Needs Admin review' : 'Provider #'.$providerId,
                'basis' => $case->liability_basis, 'compensation' => '₱'.number_format($case->merchandise_minor / 100, 2)],
            $reason);

        $this->notifyConfirmed($case, $locked);

        return $case;
    }

    /**
     * A parcel lost (or damaged beyond return) on its way back to the Seller.
     * Called by ParcelIncidentService inside the resolution transaction with
     * the parcel and incident locked.
     *
     * - The return closes as RETURN_LOST / RETURN_DAMAGED. Nothing is restocked.
     * - A buyer's return keeps its DELIVERED order: the buyer paid and handed the
     *   item over, so the refund and who pays stay with the return's existing
     *   fault rules, recorded by Admin on the Returns page.
     * - A failed delivery travelling back collected no cash, so it closes like any
     *   other lost parcel: the order becomes LOST and the pending COD is cancelled.
     * - One loss case per parcel. Liability comes from verified custody, but a
     *   buyer return's case is always held for Admin review: whether the Seller
     *   is also owed the goods depends on the fault decision, so nothing is owed
     *   until Admin confirms the liable company.
     */
    public function finalizeReturnLoss(SellerOrder $locked, Incidents $incident, Admin $admin, string $kind, string $reason, ?int $liableProviderId = null): ParcelLossCase
    {
        $existing = ParcelLossCase::query()->where('seller_order_id', $locked->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        $record = ParcelReturn::query()->where('seller_order_id', $locked->id)->lockForUpdate()->first();
        if ($record === null || ! $record->isActive()) {
            throw ValidationException::withMessages(['parcel' => 'This parcel has no open return to close.']);
        }

        [$providerId, $basis, $hubId] = $this->liability($locked);
        if ($liableProviderId !== null) {
            $chosen = LogisticsProvider::query()->whereKey($liableProviderId)->first();
            if ($chosen === null || ! $chosen->isApproved() || $chosen->archived_at !== null) {
                throw ValidationException::withMessages(['liable_provider_id' => 'Choose an approved Logistics company.']);
            }
            [$providerId, $basis] = [(int) $chosen->id, ParcelLossCase::BASIS_ADMIN];
        }

        // Refuses a return that is not physically inside the network (still with the buyer, already received).
        app(ParcelReturnService::class)->closeAsLoss($record, $admin, $kind, $reason);
        $buyerReturn = $record->isBuyerRequest();
        if (! $buyerReturn) {
            app(SellerOrderStatusService::class)->recordLoss($locked, $admin, $reason);
        }
        $this->closeTransfers($locked, $admin, $reason);

        $case = (new ParcelLossCase)->forceFill([
            'seller_order_id' => $locked->id,
            'seller_id' => $locked->seller_id,
            'incident_id' => $incident->id,
            'confirmed_by_admin_id' => $admin->id,
            'confirmed_at' => now(),
            'reason' => $reason,
            'logistics_provider_id' => $providerId,
            'liability_basis' => $providerId === null ? ParcelLossCase::BASIS_REVIEW : $basis,
            'custody_hub_id' => $hubId,
            'merchandise_minor' => max(0, (int) $locked->subtotal_minor),
            'compensation_status' => $buyerReturn || $providerId === null ? ParcelLossCase::REVIEW_REQUIRED : ParcelLossCase::PENDING,
        ]);
        if (RuntimeSchema::hasColumn('parcel_loss_cases', 'parcel_return_id')) {
            $case->forceFill(['parcel_return_id' => $record->id]);
        }
        $case->save();

        OperationsAuditLog::record($admin, 'return_parcel_'.$kind.'_confirmed', $case, 'Parcel '.($locked->tracking_number ?: '#'.$locked->id),
            ['return_status' => $record->getOriginal('status')?->value ?? 'open'],
            ['return_status' => $kind === 'lost' ? 'RETURN_LOST' : 'RETURN_DAMAGED',
                'liable' => $providerId === null ? 'Needs Admin review' : 'Provider #'.$providerId, 'basis' => $case->liability_basis,
                'compensation' => $case->compensation_status === ParcelLossCase::PENDING ? '₱'.number_format($case->merchandise_minor / 100, 2) : 'Held for Admin review'],
            $reason);

        return $case;
    }

    /**
     * The provider liable for a lost parcel, from verified custody. Custody
     * moves only on a receiving scan, so before that scan the sender still
     * holds it (sender liable), and after it the receiver does (receiver
     * liable). Anything unclear needs an Admin decision instead of a guess.
     *
     * @return array{0: ?int, 1: string, 2: ?int} provider id, basis, hub id
     */
    public function liability(SellerOrder $parcel): array
    {
        $holder = $parcel->currentCustodyProviderId();
        $hub = $parcel->custody_hub_id !== null ? (int) $parcel->custody_hub_id : null;
        $flight = $this->inFlight($parcel);

        if ($flight !== null) {
            // Not yet scanned in by the receiver: the sender holds custody.
            return $holder === null || $holder === $flight
                ? [$flight, ParcelLossCase::BASIS_BEFORE_RECEIPT, $hub]
                : [null, ParcelLossCase::BASIS_REVIEW, $hub];
        }
        // Collected by a pickup Rider but never scanned into a hub: no verified custody yet.
        if ($holder === null || $parcel->status === OrderStatus::PickedUp) {
            return [null, ParcelLossCase::BASIS_REVIEW, $hub];
        }

        return [$holder, ParcelLossCase::BASIS_AFTER_RECEIPT, $hub];
    }

    /** The sending provider of a transfer this parcel left but nobody has scanned in. */
    private function inFlight(SellerOrder $parcel): ?int
    {
        $item = ManifestItem::query()->where('seller_order_id', $parcel->id)
            ->whereNull('received_at')->whereNull('recovered_at')
            ->whereHas('manifest', fn ($m) => $m->whereNotNull('dispatched_at')->whereIn('status', ['DISPATCHED', 'CANCELLED']))
            ->with('manifest')->latest('id')->first();
        if ($item !== null) {
            return (int) $item->manifest->logistics_provider_id;
        }

        $handoff = ParcelHandoff::query()->where('seller_order_id', $parcel->id)->whereNotNull('dispatched_at')
            ->where(fn ($q) => $q->where('status', ParcelHandoffStatus::InTransit->value)
                ->orWhere(fn ($c) => $c->where('status', ParcelHandoffStatus::Cancelled->value)->whereNull('recovered_at')))
            ->latest('id')->first();

        return $handoff !== null ? (int) $handoff->origin_provider_id : null;
    }

    /** Close the parcel's open transfers, so no batch or handoff waits forever for a lost parcel. */
    private function closeTransfers(SellerOrder $parcel, Admin $admin, string $reason): void
    {
        $note = 'Confirmed lost by Admin: '.$reason;
        $manifests = ManifestService::class;
        $this->lockedByKey(ManifestItem::query()->where('seller_order_id', $parcel->id)->whereNull('received_at')->whereNull('released_at'), ManifestItem::class)
            ->each(function (ManifestItem $item) use ($note, $manifests): void {
                $item->markMissing($note);
                app($manifests)->updateManifestStatusForExceptions($item->manifest()->firstOrFail());
            });

        $this->lockedByKey(ParcelHandoff::query()->where('seller_order_id', $parcel->id)
            ->whereIn('status', [ParcelHandoffStatus::Requested->value, ParcelHandoffStatus::Accepted->value, ParcelHandoffStatus::InTransit->value]), ParcelHandoff::class)
            ->each(fn (ParcelHandoff $handoff) => $handoff->forceFill([
                'status' => ParcelHandoffStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by_type' => 'admin',
                'cancelled_by_id' => $admin->id,
                'cancellation_reason' => mb_substr($note, 0, 1000),
            ])->save());
    }

    // ── 2. Liability and the manual compensation payment ───────────────────

    /** Admin names the liable company when custody could not decide it. */
    public function assignLiability(ParcelLossCase $case, Admin $admin, int $providerId, string $reason): ParcelLossCase
    {
        return DB::transaction(function () use ($case, $admin, $providerId, $reason): ParcelLossCase {
            $locked = $this->lockCase($case);
            $this->requireReason($reason);
            if ($locked->isPaid()) {
                throw ValidationException::withMessages(['liability' => 'The compensation was already recorded as paid. Use an adjustment instead.']);
            }
            $provider = LogisticsProvider::query()->whereKey($providerId)->first();
            if ($provider === null || ! $provider->isApproved() || $provider->archived_at !== null) {
                throw ValidationException::withMessages(['liable_provider_id' => 'Choose an approved Logistics company.']);
            }
            if ((int) $locked->logistics_provider_id === (int) $provider->id && $locked->compensation_status === ParcelLossCase::PENDING) {
                return $locked; // Repeated: nothing changes.
            }

            $old = ['liable' => $locked->logistics_provider_id === null ? 'Needs Admin review' : 'Provider #'.$locked->logistics_provider_id];
            $locked->forceFill([
                'logistics_provider_id' => $provider->id,
                'liability_basis' => ParcelLossCase::BASIS_ADMIN,
                'compensation_status' => ParcelLossCase::PENDING,
            ])->save();
            OperationsAuditLog::record($admin, 'lost_compensation_assigned', $locked, 'Lost parcel #'.$locked->seller_order_id,
                $old, ['liable' => $provider->business_name], $reason);
            $this->notifyProvider($locked, $provider);

            return $locked;
        });
    }

    /**
     * Admin records that the liable provider paid the Seller. The payment
     * happens outside Zefanya; this is the confirmation. It can be recorded
     * once: a repeat with the same reference is a no-op, a different
     * reference is refused.
     */
    public function recordCompensationPayment(ParcelLossCase $case, Admin $admin, string $reference, string $paidOn): ParcelLossCase
    {
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 100) {
            throw ValidationException::withMessages(['reference' => 'Enter the payment reference (up to 100 characters).']);
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) || strtotime($paidOn) === false || $paidOn > now()->toDateString()) {
            throw ValidationException::withMessages(['paid_on' => 'Enter the date it was paid, not in the future.']);
        }

        return DB::transaction(function () use ($case, $admin, $reference, $paidOn): ParcelLossCase {
            $locked = $this->lockCase($case);
            if ($locked->isPaid()) {
                if ($locked->compensation_reference === $reference) {
                    return $locked;
                }
                throw ValidationException::withMessages(['reference' => 'This compensation was already recorded as paid (reference '.$locked->compensation_reference.').']);
            }
            if ($locked->compensation_status === ParcelLossCase::REVIEW_REQUIRED || $locked->logistics_provider_id === null) {
                throw ValidationException::withMessages(['reference' => 'Decide which Logistics company is liable before recording the payment.']);
            }
            if ($locked->merchandise_minor < 1) {
                throw ValidationException::withMessages(['reference' => 'There is no merchandise value to pay.']);
            }

            $locked->forceFill([
                'compensation_status' => ParcelLossCase::PAID,
                'compensation_reference' => $reference,
                'compensation_paid_on' => $paidOn,
                'compensation_paid_at' => now(),
                'compensation_paid_by_admin_id' => $admin->id,
            ])->save();
            OperationsAuditLog::record($admin, 'lost_compensation_paid', $locked, 'Lost parcel #'.$locked->seller_order_id,
                ['compensation' => 'Owed'], ['compensation' => 'Paid', 'amount' => '₱'.number_format($locked->merchandise_minor / 100, 2), 'reference' => $reference, 'paid_on' => $paidOn]);

            $seller = Seller::query()->find($locked->seller_id);
            $tracking = $locked->sellerOrder?->tracking_number ?? '#'.$locked->seller_order_id;
            DB::afterCommit(fn () => rescue(fn () => $seller?->notify(new ParcelLostConfirmed($locked, ParcelLostConfirmed::SELLER, $tracking)), report: true));

            return $locked;
        });
    }

    /**
     * A paid compensation is never edited. A later correction (for example the
     * parcel turned up and the Seller returns part of the money) is a signed,
     * audited adjustment row.
     */
    public function recordAdjustment(ParcelLossCase $case, Admin $admin, int $amountMinor, string $reason): ParcelLossAdjustment
    {
        $this->requireReason($reason);
        if ($amountMinor === 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount other than zero. Use a negative amount to record money given back.']);
        }

        return DB::transaction(function () use ($case, $admin, $amountMinor, $reason): ParcelLossAdjustment {
            $locked = $this->lockCase($case);
            if (! $locked->isPaid()) {
                throw ValidationException::withMessages(['amount' => 'Adjustments are only for a compensation already recorded as paid.']);
            }
            $net = $locked->merchandise_minor + (int) $locked->adjustments()->sum('amount_minor') + $amountMinor;
            if ($net < 0) {
                throw ValidationException::withMessages(['amount' => 'The adjustment would take the compensation below zero.']);
            }

            $adjustment = (new ParcelLossAdjustment)->forceFill([
                'parcel_loss_case_id' => $locked->id, 'admin_id' => $admin->id, 'amount_minor' => $amountMinor, 'reason' => trim($reason),
            ]);
            $adjustment->save();
            OperationsAuditLog::record($admin, 'lost_compensation_adjusted', $locked, 'Lost parcel #'.$locked->seller_order_id,
                ['net' => '₱'.number_format(($net - $amountMinor) / 100, 2)], ['net' => '₱'.number_format($net / 100, 2), 'change' => ($amountMinor < 0 ? '−' : '+').'₱'.number_format(abs($amountMinor) / 100, 2)], $reason);

            return $adjustment;
        });
    }

    // ── 3. Bulk processing ─────────────────────────────────────────────────

    /**
     * The custodian reports several missing parcels of one transfer together.
     * Each gets its own exception, created all-or-nothing in one transaction.
     * Only parcels still waiting for a receiving scan are eligible, so parcels
     * already received are untouched and custody never changes.
     *
     * @param  list<int>  $sellerOrderIds
     * @return Collection<int, Incidents>
     */
    public function reportMissingTogether(string $transferType, int $transferId, LogisticsProvider $provider, array $sellerOrderIds, string $reason): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $sellerOrderIds)));
        $reason = trim($reason);
        if ($ids === []) {
            throw ValidationException::withMessages(['seller_order_ids' => 'Select at least one parcel.']);
        }
        if (count($ids) > self::MAX_BULK) {
            throw ValidationException::withMessages(['seller_order_ids' => 'Select '.self::MAX_BULK.' parcels or fewer at a time.']);
        }
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'Give the reason in 255 characters or fewer.']);
        }

        return DB::transaction(function () use ($transferType, $transferId, $provider, $ids, $reason): Collection {
            $eligible = $this->pendingParcelIds($transferType, $transferId, $provider);
            $outside = array_values(array_diff($ids, $eligible));
            if ($outside !== []) {
                throw ValidationException::withMessages(['seller_order_ids' => 'Some selected parcels are not waiting for a receiving scan on this transfer (already received, released, or not yours). Nothing was reported.']);
            }

            $incidents = collect();
            foreach (SellerOrder::query()->whereIn('id', $ids)->orderBy('id')->get() as $parcel) {
                $incidents->push(app(ParcelIncidentService::class)->reportOperational($parcel, $provider, [
                    'type' => 'missing',
                    'reason' => $reason,
                    'description' => 'Reported missing together with '.(count($ids) - 1).' other parcel(s) from '.($transferType === 'manifest' ? 'transfer batch' : 'handoff').' #'.$transferId.'.',
                ]));
            }

            return $incidents;
        });
    }

    /** @return list<int> Parcels of this transfer that left the sending hub and nobody has scanned in. */
    private function pendingParcelIds(string $transferType, int $transferId, LogisticsProvider $provider): array
    {
        if ($transferType === 'manifest') {
            return ManifestItem::query()->where('manifest_id', $transferId)
                ->whereNull('received_at')->whereNull('released_at')
                ->whereHas('manifest', fn ($m) => $m->where('logistics_provider_id', $provider->id)->where('status', 'DISPATCHED'))
                ->pluck('seller_order_id')->map(fn ($id): int => (int) $id)->all();
        }

        return ParcelHandoff::query()->whereKey($transferId)->where('origin_provider_id', $provider->id)
            ->where('status', ParcelHandoffStatus::InTransit->value)
            ->pluck('seller_order_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Admin confirms several parcels lost together. Each parcel is resolved in
     * its own transaction through the same path as a single confirmation, so
     * one failure never blocks or half-applies the others, and a repeat is
     * harmless.
     *
     * @param  list<int>  $incidentIds
     * @return array{confirmed: list<int>, failed: array<int, string>}
     */
    public function confirmMany(array $incidentIds, Admin $admin, string $reason, ?int $liableProviderId = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $incidentIds)));
        if ($ids === [] || count($ids) > self::MAX_BULK) {
            throw ValidationException::withMessages(['incident_ids' => 'Select between 1 and '.self::MAX_BULK.' parcels.']);
        }

        $result = ['confirmed' => [], 'failed' => []];
        foreach (Incidents::query()->whereIn('id', $ids)->orderBy('id')->get() as $incident) {
            try {
                app(ParcelIncidentService::class)->resolve($incident, $admin, [
                    'event' => 'CLOSE_LOST', 'reason' => $reason,
                    'context' => $liableProviderId !== null ? ['liable_provider_id' => $liableProviderId] : [],
                ]);
                $result['confirmed'][] = (int) $incident->incident_seller_order_id;
            } catch (ValidationException $e) {
                $result['failed'][(int) $incident->incident_seller_order_id] = (string) collect($e->errors())->flatten()->first();
            }
        }

        return $result;
    }

    // ── 5. Recovery ────────────────────────────────────────────────────────

    /**
     * A hub's verified scan found a parcel already confirmed lost. This only
     * records where it is: the order stays LOST, stock is not restored, and
     * nothing reopens. Admin then decides what happens to it.
     */
    public function recordRecovery(ParcelLossCase $case, LogisticsProvider $provider, int $hubId, ?string $token): ParcelLossCase
    {
        $recordedNow = false;
        $recovered = DB::transaction(function () use ($case, $provider, $hubId, $token, &$recordedNow): ParcelLossCase {
            $parcel = SellerOrder::query()->whereKey($case->seller_order_id)->lockForUpdate()->firstOrFail();
            $locked = $this->lockCase($case);
            $hub = LogisticsHub::query()->whereKey($hubId)->where('logistics_provider_id', $provider->id)->first();
            if ($hub === null || ! $hub->isOperational()) {
                throw ValidationException::withMessages(['hub' => 'Choose one of your own operational hubs.']);
            }
            if ($locked->recovered_at !== null) {
                if ((int) $locked->recovered_hub_id === (int) $hub->id) {
                    return $locked; // Same hub recorded again: nothing changes.
                }
                throw ValidationException::withMessages(['hub' => 'This parcel was already recorded as found at another hub.']);
            }
            if (! $this->canRecover($locked, $provider)) {
                throw ValidationException::withMessages(['hub' => 'Only the responsible company, or one that moved this parcel, can record it found.']);
            }
            app(ParcelHandoffVerification::class)->assertValidForLogistics($provider, $parcel, ParcelHandoffVerification::ACTION_LOSS_RECOVER, $token, (int) $hub->id);

            $locked->forceFill([
                'recovered_at' => now(), 'recovered_hub_id' => $hub->id, 'recovered_by_provider_id' => $provider->id,
            ])->save();
            $recordedNow = true;
            // Where the parcel physically is. The status stays LOST.
            $parcel->forceFill(['custody_provider_id' => $provider->id, 'custody_hub_id' => $hub->id])->save();
            OperationsAuditLog::record($provider, 'lost_parcel_recovered', $locked, 'Lost parcel #'.$locked->seller_order_id,
                ['location' => 'Unknown'], ['location' => $hub->name, 'order' => 'Stays closed as lost']);

            return $locked;
        });

        // A repeat of an already-recorded find changes nothing and tells nobody again.
        if ($recordedNow) {
            $parcel = $recovered->sellerOrder()->firstOrFail();
            if ($token !== null) {
                app(ParcelHandoffVerification::class)->consumeAfterSuccess(fn () => app(ParcelHandoffVerification::class)
                    ->consumeForLogistics($provider, $parcel, ParcelHandoffVerification::ACTION_LOSS_RECOVER, $token, (int) $recovered->recovered_hub_id));
            }
            $this->notifyRecovered($recovered);
        }

        return $recovered;
    }

    /** Admin decides what happens to a parcel found after it was confirmed lost. */
    public function decideDisposition(ParcelLossCase $case, Admin $admin, string $disposition, string $note): ParcelLossCase
    {
        $this->requireReason($note);
        if (! array_key_exists($disposition, ParcelLossCase::DISPOSITIONS)) {
            throw ValidationException::withMessages(['disposition' => 'Choose one of the listed decisions.']);
        }

        return DB::transaction(function () use ($case, $admin, $disposition, $note): ParcelLossCase {
            $locked = $this->lockCase($case);
            if ($locked->recovered_at === null) {
                throw ValidationException::withMessages(['disposition' => 'No hub has recorded this parcel as found yet.']);
            }
            if ($locked->disposition !== null) {
                if ($locked->disposition === $disposition) {
                    return $locked;
                }
                throw ValidationException::withMessages(['disposition' => 'A decision was already recorded for this parcel.']);
            }
            $locked->forceFill([
                'disposition' => $disposition, 'disposition_note' => trim($note),
                'disposition_by_admin_id' => $admin->id, 'disposition_at' => now(),
            ])->save();
            OperationsAuditLog::record($admin, 'lost_parcel_disposition', $locked, 'Lost parcel #'.$locked->seller_order_id,
                ['decision' => 'Pending'], ['decision' => ParcelLossCase::DISPOSITIONS[$disposition], 'order' => 'Stays closed as lost; stock unchanged'], $note);

            return $locked;
        });
    }

    public function canRecover(ParcelLossCase $case, LogisticsProvider $provider): bool
    {
        if ((int) $case->logistics_provider_id === (int) $provider->id) {
            return true;
        }
        $parcel = $case->sellerOrder;

        return $parcel !== null && ((int) $parcel->carrier_provider_id === (int) $provider->id
            || ParcelHandoff::query()->where('seller_order_id', $parcel->id)
                ->where(fn ($q) => $q->where('origin_provider_id', $provider->id)->orWhere('destination_provider_id', $provider->id))->exists()
            || ManifestItem::query()->where('seller_order_id', $parcel->id)->whereHas('manifest', fn ($m) => $m->where('logistics_provider_id', $provider->id))->exists());
    }

    // ── Reads for the pages ────────────────────────────────────────────────

    /** @return EloquentCollection<int, ParcelLossCase> */
    public function forProvider(LogisticsProvider $provider): EloquentCollection
    {
        if (! self::available()) {
            return new EloquentCollection;
        }

        return ParcelLossCase::query()->where('logistics_provider_id', $provider->id)
            ->with(['sellerOrder:id,tracking_number,order_id,seller_id', 'seller:id,business_name', 'adjustments'])->latest('id')->get();
    }

    /** @return EloquentCollection<int, ParcelLossCase> */
    public function forSeller(Seller $seller): EloquentCollection
    {
        if (! self::available()) {
            return new EloquentCollection;
        }

        return ParcelLossCase::query()->where('seller_id', $seller->id)
            ->with(['sellerOrder:id,tracking_number,order_id', 'provider:id,business_name', 'adjustments'])->latest('id')->get();
    }

    /** The case that decides how a parcel's tracking is described, if any. */
    public function caseFor(SellerOrder $parcel): ?ParcelLossCase
    {
        return self::available() ? ParcelLossCase::query()->where('seller_order_id', $parcel->id)->first() : null;
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * Rows matching $query, locked by primary key. Locking a range on a secondary index takes
     * next-key locks that can deadlock with a concurrent insert; finding the ids with a plain read and
     * then locking exactly those rows cannot.
     *
     * @param  class-string<Model>  $model
     */
    private function lockedByKey(Builder $query, string $model): EloquentCollection
    {
        $ids = $query->pluck('id');

        return $ids->isEmpty()
            ? new EloquentCollection
            : $model::query()->whereKey($ids->all())->orderBy('id')->lockForUpdate()->get();
    }

    private function lockCase(ParcelLossCase $case): ParcelLossCase
    {
        return ParcelLossCase::query()->whereKey($case->id)->with('sellerOrder')->lockForUpdate()->firstOrFail();
    }

    private function requireReason(string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Give a reason in 1000 characters or fewer.']);
        }
    }

    private function notifyConfirmed(ParcelLossCase $case, SellerOrder $parcel): void
    {
        $parcel->loadMissing(['order.buyer', 'seller']);
        $tracking = $parcel->tracking_number ?: '#'.$parcel->id;
        $providerIds = collect([$case->logistics_provider_id, $parcel->custody_provider_id, $parcel->carrier_provider_id])->filter()->unique()->values();

        DB::afterCommit(function () use ($case, $parcel, $tracking, $providerIds): void {
            $send = function (?object $recipient, string $audience) use ($case, $parcel, $tracking): void {
                if ($recipient === null) {
                    return;
                }
                try {
                    $recipient->notify(new ParcelLostConfirmed($case, $audience, $tracking, $parcel->order_id));
                } catch (Throwable $e) {
                    report($e); // The loss is committed; keep notifying the others.
                }
            };
            $send($parcel->order?->buyer, ParcelLostConfirmed::BUYER);
            $send($parcel->seller, ParcelLostConfirmed::SELLER);
            foreach (LogisticsProvider::query()->whereIn('id', $providerIds)->get() as $provider) {
                $send($provider, ParcelLostConfirmed::LOGISTICS);
            }
        });
    }

    private function notifyProvider(ParcelLossCase $case, LogisticsProvider $provider): void
    {
        $tracking = $case->sellerOrder?->tracking_number ?? '#'.$case->seller_order_id;
        DB::afterCommit(fn () => rescue(fn () => $provider->notify(new ParcelLostConfirmed($case, ParcelLostConfirmed::LOGISTICS, $tracking)), report: true));
    }

    private function notifyRecovered(ParcelLossCase $case): void
    {
        $tracking = $case->sellerOrder?->tracking_number ?? '#'.$case->seller_order_id;
        $admins = Admin::query()->whereIn('role', ['admin', 'super_admin'])->get();
        foreach ($admins as $admin) {
            rescue(fn () => $admin->notify(new LostParcelFound($case, $tracking)), report: true);
        }
    }
}
