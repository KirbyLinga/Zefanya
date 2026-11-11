<?php

namespace App\Services\Logistics;

use App\Enums\AdminRole;
use App\Exceptions\StaleStateException;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\OriginSharePayout;
use App\Notifications\Admin\LogisticsPaymentRecorded;
use App\Notifications\Logistics\LogisticsPaymentDecided;
use App\Notifications\Logistics\OriginSharePaymentRecorded;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Logistics to Zefanya money: a company keeps each parcel's shipping fee and
 * sends Zefanya the rest of the cash. Zefanya confirms what actually arrived.
 * A cross-provider fee is split 40/60; see CodRemittance::originShareMinor().
 */
class LogisticsRemittanceService
{
    public const STAGE_RIDER = 'rider';

    public const STAGE_READY = 'ready';

    public const STAGE_SENT = 'sent';

    public const STAGE_SETTLED = 'settled';

    /** Cash held longer than this without moving on is flagged as overdue. */
    public const OVERDUE_AFTER_DAYS = 3;

    public const RECONCILE_ALLOCATE = 'allocated';

    public const RECONCILE_KEEP = 'kept';

    /**
     * Parcels, cash and shipping in each stage of the journey to Zefanya.
     *
     * @return array<string, array{parcels: int, codMinor: int, shippingMinor: int, dueMinor: int}>
     */
    public function totals(LogisticsProvider $provider): array
    {
        $empty = ['parcels' => 0, 'codMinor' => 0, 'shippingMinor' => 0, 'dueMinor' => 0];
        $totals = array_fill_keys([self::STAGE_RIDER, self::STAGE_READY, self::STAGE_SENT, self::STAGE_SETTLED], $empty);

        $rows = $this->withShipping(CodRemittance::query()->where('cod_remittances.logistics_provider_id', $provider->getKey()))
            ->selectRaw(
                'CASE WHEN cod_remittances.status = ? THEN ? WHEN cod_remittances.status = ? THEN ? '
                .'WHEN cod_remittances.zefanya_received_at IS NOT NULL THEN ? '
                .'WHEN cod_remittances.logistics_remittance_id IS NOT NULL THEN ? ELSE ? END as stage',
                [CodRemittance::PENDING_REMITTANCE, self::STAGE_RIDER, CodRemittance::PARTIALLY_REMITTED, self::STAGE_RIDER,
                    self::STAGE_SETTLED, self::STAGE_SENT, self::STAGE_READY],
            )
            ->selectRaw('COUNT(*) as parcels, SUM(cod_remittances.amount_minor) as cod_minor, SUM('.CodRemittance::KEPT_SHIPPING_SQL.') as shipping_minor')
            ->groupBy('stage')
            ->get();

        foreach ($rows as $row) {
            $totals[$row->stage] = [
                'parcels' => (int) $row->parcels,
                'codMinor' => (int) $row->cod_minor,
                'shippingMinor' => (int) $row->shipping_minor,
                'dueMinor' => (int) $row->cod_minor - (int) $row->shipping_minor,
            ];
        }

        return $totals;
    }

    public function overdueCutoff(): Carbon
    {
        return now()->subDays(self::OVERDUE_AFTER_DAYS);
    }

    /**
     * What each company has collected but not yet reported sending.
     *
     * @return Collection<int, array{provider: string, parcels: int, dueMinor: int}>
     */
    public function unsentByProvider(): Collection
    {
        return $this->withShipping($this->readyQuery(CodRemittance::query()))
            ->selectRaw('cod_remittances.logistics_provider_id, COUNT(*) as parcels, SUM(cod_remittances.amount_minor) as cod_minor, SUM('.CodRemittance::KEPT_SHIPPING_SQL.') as shipping_minor')
            ->selectRaw('MIN(cod_remittances.confirmed_at) as oldest_at')
            ->selectRaw('SUM(CASE WHEN cod_remittances.confirmed_at < ? THEN 1 ELSE 0 END) as overdue_parcels', [$this->overdueCutoff()->toDateTimeString()])
            ->groupBy('cod_remittances.logistics_provider_id')
            ->with('provider:id,business_name')
            ->get()
            ->map(fn (CodRemittance $row): array => [
                'provider' => $row->provider?->business_name ?? 'Unknown provider',
                'parcels' => (int) $row->parcels,
                'dueMinor' => (int) $row->cod_minor - (int) $row->shipping_minor,
                'oldestAt' => $row->oldest_at === null ? null : Carbon::parse($row->oldest_at),
                'overdueParcels' => (int) $row->overdue_parcels,
            ])
            ->sortByDesc('dueMinor')
            ->values();
    }

    /**
     * Origin carriers' 40% of cross-provider fees. `receivedMinor` is the part
     * whose cash has reached Zefanya; `paidMinor` is what Zefanya has paid out.
     *
     * @return array{parcels: int, shareMinor: int, receivedMinor: int, paidMinor: int}
     */
    public function originShares(?LogisticsProvider $carrier = null): array
    {
        $row = $this->withShipping(CodRemittance::query())
            ->whereNotNull('seller_orders.carrier_provider_id')
            ->whereColumn('seller_orders.carrier_provider_id', '<>', 'cod_remittances.logistics_provider_id')
            ->when($carrier !== null, fn (Builder $query) => $query->where('seller_orders.carrier_provider_id', $carrier->getKey()))
            ->selectRaw('COUNT(*) as parcels, COALESCE(SUM('.CodRemittance::ORIGIN_SHARE_SQL.'), 0) as share_minor')
            ->selectRaw('COALESCE(SUM(CASE WHEN cod_remittances.zefanya_received_at IS NOT NULL THEN '.CodRemittance::ORIGIN_SHARE_SQL.' ELSE 0 END), 0) as received_minor')
            ->selectRaw('COALESCE(SUM(CASE WHEN cod_remittances.origin_share_payout_id IS NOT NULL THEN '.CodRemittance::ORIGIN_SHARE_SQL.' ELSE 0 END), 0) as paid_minor')
            ->first();

        return [
            'parcels' => (int) $row?->parcels,
            'shareMinor' => (int) $row?->share_minor,
            'receivedMinor' => (int) $row?->received_minor,
            'paidMinor' => (int) $row?->paid_minor,
        ];
    }

    /**
     * What Zefanya can pay each origin carrier now: its share on parcels whose
     * cash Zefanya has confirmed and that no payout covers yet. `throughId` is
     * the newest parcel shown, so a parcel settled after the page loaded is
     * never paid without being seen.
     *
     * @return Collection<int, array{providerId: int, provider: string, parcels: int, amountMinor: int, throughId: int}>
     */
    public function originOwedByProvider(): Collection
    {
        $rows = $this->originOwedQuery(CodRemittance::query())
            ->selectRaw('seller_orders.carrier_provider_id as origin_id, COUNT(*) as parcels, SUM('.CodRemittance::ORIGIN_SHARE_SQL.') as amount_minor, MAX(cod_remittances.id) as through_id')
            ->groupBy('seller_orders.carrier_provider_id')
            ->get();
        $names = LogisticsProvider::query()->whereKey($rows->pluck('origin_id'))->pluck('business_name', 'id');

        return $rows->map(fn (CodRemittance $row): array => [
            'providerId' => (int) $row->origin_id,
            'provider' => $names[$row->origin_id] ?? 'Unknown provider',
            'parcels' => (int) $row->parcels,
            'amountMinor' => (int) $row->amount_minor,
            'throughId' => (int) $row->through_id,
        ])->sortByDesc('amountMinor')->values();
    }

    /**
     * Record that Zefanya paid an origin carrier its 40% share. Each parcel can
     * be in one payout only, and only after Zefanya confirmed receiving its cash.
     */
    public function payOriginShare(Admin $admin, LogisticsProvider $origin, int $throughId, int $expectedMinor, string $reference, string $paidOn): OriginSharePayout
    {
        $this->authorizeAdmin($admin);
        $reference = trim($reference);

        return DB::transaction(function () use ($admin, $origin, $throughId, $expectedMinor, $reference, $paidOn): OriginSharePayout {
            $origin = LogisticsProvider::query()->whereKey($origin->getKey())->lockForUpdate()->firstOrFail();

            if (OriginSharePayout::query()->where('logistics_provider_id', $origin->getKey())->where('reference', $reference)->whereDoesntHave('reversal')->exists()) {
                throw ValidationException::withMessages(['reference' => 'This reference was already used for another payment to this company.']);
            }

            $parcels = $this->originOwedQuery(CodRemittance::query())
                ->where('seller_orders.carrier_provider_id', $origin->getKey())
                ->where('cod_remittances.id', '<=', $throughId)
                ->select('cod_remittances.*')
                ->with('sellerOrder:id,shipping_fee_minor,carrier_provider_id')
                ->orderBy('cod_remittances.id')->lockForUpdate()->get();
            if ($parcels->isEmpty()) {
                throw StaleStateException::field('origin', 'Nothing is owed to this company right now. It may already have been paid.');
            }

            $amount = (int) $parcels->sum(fn (CodRemittance $parcel): int => $parcel->originShareMinor());
            if ($amount !== $expectedMinor) {
                throw StaleStateException::field('origin', 'The amount owed changed. Check the new amount before recording the payment.');
            }

            $payout = new OriginSharePayout;
            $payout->forceFill([
                'logistics_provider_id' => $origin->getKey(),
                'parcel_count' => $parcels->count(),
                'amount_minor' => $amount,
                'reference' => $reference,
                'paid_on' => $paidOn,
                'status' => OriginSharePayout::PAID,
                'paid_by_admin_id' => $admin->getKey(),
            ])->save();

            CodRemittance::query()->whereKey($parcels->modelKeys())->whereNull('origin_share_payout_id')
                ->update(['origin_share_payout_id' => $payout->getKey()]);

            OperationsAuditLog::record(
                $admin, 'origin_share_paid', $payout, 'Origin payout #'.$payout->getKey(),
                ['status' => null, 'amount' => null],
                ['status' => 'Paid', 'amount' => $this->peso($amount), 'reference' => $reference, 'paid_on' => $paidOn],
            );

            $this->notifyOriginAfterCommit($payout);

            return $payout;
        }, 3);
    }

    /**
     * Cross-provider parcels sent under the old "full fee to Zefanya" rule. The 40/60 split
     * was not applied to them, so Admin decides what to do with their shipping fee.
     *
     * @return Collection<int, CodRemittance>
     */
    public function historicalToReconcile(): Collection
    {
        return $this->legacyQuery(CodRemittance::query())
            ->whereNull('cod_remittances.reconciled_at')
            ->select('cod_remittances.*')
            ->with(['sellerOrder:id,tracking_number,shipping_fee_minor,carrier_provider_id', 'sellerOrder.carrierProvider:id,business_name', 'provider:id,business_name'])
            ->orderBy('cod_remittances.id')->get();
    }

    /**
     * Allocated historical parcels whose delivering company has not yet been paid its 60%.
     *
     * @return Collection<int, CodRemittance>
     */
    public function destinationOwed(): Collection
    {
        return CodRemittance::query()->where('reconciliation', self::RECONCILE_ALLOCATE)
            ->whereNull('destination_paid_on')
            ->with(['sellerOrder:id,tracking_number', 'provider:id,business_name'])
            ->orderBy('id')->get();
    }

    /**
     * One-time decision on an old parcel: split its fee 40/60 (the origin 40% then follows the
     * normal origin payout; the delivering company's 60% is recorded as owed), or leave the
     * fee with Zefanya. Every decision is final and audited.
     */
    public function reconcileHistorical(Admin $admin, CodRemittance $parcel, string $decision): void
    {
        $this->authorizeAdmin($admin);
        if (! in_array($decision, [self::RECONCILE_ALLOCATE, self::RECONCILE_KEEP], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose to allocate the fee 40/60 or keep it with Zefanya.']);
        }

        DB::transaction(function () use ($admin, $parcel, $decision): void {
            $locked = $this->legacyQuery(CodRemittance::query())->whereNull('cod_remittances.reconciled_at')
                ->where('cod_remittances.id', $parcel->getKey())->select('cod_remittances.*')
                ->with('sellerOrder:id,shipping_fee_minor,carrier_provider_id')->lockForUpdate()->first();
            if ($locked === null) {
                throw StaleStateException::field('parcel', 'This parcel no longer needs reconciling. It may already have been decided.');
            }

            $fee = (int) $locked->sellerOrder->shipping_fee_minor;
            $origin = $decision === self::RECONCILE_ALLOCATE ? Money::percentage($fee, CodRemittance::ORIGIN_SHARE_PERCENT) : 0;
            $locked->forceFill([
                'reconciliation' => $decision,
                'reconciled_at' => now(),
                'reconciled_by_admin_id' => $admin->getKey(),
                'origin_share_minor' => $origin,
                'destination_share_minor' => $decision === self::RECONCILE_ALLOCATE ? $fee - $origin : null,
            ])->save();

            OperationsAuditLog::record(
                $admin, 'historical_fee_reconciled', $locked, 'Parcel #'.$locked->seller_order_id,
                ['origin_share' => '₱0.00', 'decision' => null],
                ['decision' => $decision === self::RECONCILE_ALLOCATE ? 'Allocated 40/60' : 'Kept with Zefanya', 'origin_share' => $this->peso($origin), 'delivering_company_share' => $this->peso($decision === self::RECONCILE_ALLOCATE ? $fee - $origin : 0)],
            );
        }, 3);
    }

    /** Record that Zefanya paid the delivering company its 60% on an allocated historical parcel. */
    public function payDestinationShare(Admin $admin, CodRemittance $parcel, string $reference, string $paidOn): void
    {
        $this->authorizeAdmin($admin);
        $reference = trim($reference);

        DB::transaction(function () use ($admin, $parcel, $reference, $paidOn): void {
            $locked = CodRemittance::query()->whereKey($parcel->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->reconciliation !== self::RECONCILE_ALLOCATE || $locked->destination_paid_on !== null) {
                throw StaleStateException::field('parcel', 'Nothing is owed to the delivering company on this parcel, or it was already paid.');
            }

            $locked->forceFill([
                'destination_paid_reference' => $reference,
                'destination_paid_on' => $paidOn,
                'destination_paid_by_admin_id' => $admin->getKey(),
            ])->save();

            OperationsAuditLog::record(
                $admin, 'historical_destination_share_paid', $locked, 'Parcel #'.$locked->seller_order_id,
                ['status' => 'Owed'],
                ['status' => 'Paid', 'amount' => $this->peso((int) $locked->destination_share_minor), 'reference' => $reference, 'paid_on' => $paidOn],
            );
        }, 3);
    }

    /**
     * Parcels the company can include in its next payment, oldest first.
     *
     * @return Collection<int, CodRemittance>
     */
    public function readyParcels(LogisticsProvider $provider): Collection
    {
        return $this->readyQuery(CodRemittance::query()->where('logistics_provider_id', $provider->getKey()))
            ->with('sellerOrder:id,tracking_number,shipping_fee_minor,carrier_provider_id')
            ->orderBy('id')->get();
    }

    /**
     * Record that the company sent Zefanya the money for the chosen parcels. The
     * expected amount is what the page showed, so a stale page stops the save
     * instead of recording a different amount from what was actually sent.
     *
     * @param  list<int>  $parcelIds
     */
    public function submit(LogisticsProvider $provider, array $parcelIds, int $expectedMinor, string $reference): LogisticsRemittance
    {
        $reference = trim($reference);

        $remittance = DB::transaction(function () use ($provider, $parcelIds, $expectedMinor, $reference): LogisticsRemittance {
            $provider = LogisticsProvider::query()->whereKey($provider->getKey())->lockForUpdate()->firstOrFail();

            $used = LogisticsRemittance::query()
                ->where('logistics_provider_id', $provider->getKey())
                ->where('reference', $reference)
                ->where('status', '!=', LogisticsRemittance::REJECTED)
                ->exists();
            if ($used) {
                throw ValidationException::withMessages(['reference' => 'This reference was already used for another payment.']);
            }

            $parcels = $this->readyQuery(CodRemittance::query()->where('logistics_provider_id', $provider->getKey()))
                ->whereKey(array_unique($parcelIds))
                ->with('sellerOrder:id,shipping_fee_minor,carrier_provider_id')
                ->orderBy('id')->lockForUpdate()->get();
            if ($parcelIds === [] || $parcels->count() !== count(array_unique($parcelIds))) {
                throw StaleStateException::field('remittance', 'One of these parcels can no longer be sent. Check the list and try again.');
            }

            $cod = (int) $parcels->sum('amount_minor');
            $shipping = (int) $parcels->sum(fn (CodRemittance $parcel): int => $parcel->keptShippingMinor());
            $amount = $cod - $shipping;
            if ($amount !== $expectedMinor) {
                throw StaleStateException::field('remittance', 'The amount due changed. Check the new amount before recording your payment.');
            }

            $remittance = new LogisticsRemittance;
            $remittance->forceFill([
                'logistics_provider_id' => $provider->getKey(),
                'parcel_count' => $parcels->count(),
                'cod_total_minor' => $cod,
                'shipping_total_minor' => $shipping,
                'amount_minor' => $amount,
                'reference' => $reference,
                'status' => LogisticsRemittance::SUBMITTED,
            ])->save();

            CodRemittance::query()->whereKey($parcels->modelKeys())
                ->update(['logistics_remittance_id' => $remittance->getKey()]);

            OperationsAuditLog::record(
                $provider, 'logistics_remittance_submitted', $remittance, 'Payment #'.$remittance->getKey(),
                ['status' => null, 'amount' => null],
                ['status' => 'Submitted', 'amount' => $this->peso($amount), 'reference' => $reference],
            );

            return $remittance;
        }, 3);

        $this->safely(fn () => Notification::send(
            Admin::query()->whereIn('role', [AdminRole::Admin->value, AdminRole::SuperAdmin->value])->get(),
            new LogisticsPaymentRecorded($remittance->load('provider')),
        ));

        return $remittance;
    }

    /** Return false for an already-confirmed payment without changing its record. */
    /**
     * `$acceptedIds` lets Admin confirm only the parcels the money covers when less arrived
     * than stated. The rest become due again. Null confirms every parcel in the payment.
     *
     * @param  list<int>|null  $acceptedIds
     */
    public function confirm(LogisticsRemittance $remittance, Admin $admin, int $receivedMinor, ?array $acceptedIds = null): bool
    {
        $this->authorizeAdmin($admin);

        $confirmed = DB::transaction(function () use ($remittance, $admin, $receivedMinor, $acceptedIds): bool {
            $locked = LogisticsRemittance::query()->whereKey($remittance->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === LogisticsRemittance::CONFIRMED) {
                return false;
            }
            if ($locked->status !== LogisticsRemittance::SUBMITTED) {
                throw StaleStateException::field('remittance', 'Only a submitted payment can be confirmed.');
            }
            $parcels = CodRemittance::query()->where('logistics_remittance_id', $locked->getKey())
                ->with('sellerOrder:id,shipping_fee_minor,carrier_provider_id')->orderBy('id')->lockForUpdate()->get();
            $accepted = $acceptedIds === null ? $parcels : $parcels->whereIn('id', $acceptedIds)->values();
            if ($acceptedIds !== null && $accepted->count() !== count(array_unique($acceptedIds))) {
                throw ValidationException::withMessages(['parcels' => 'One of the chosen parcels is not part of this payment.']);
            }
            if ($accepted->isEmpty()) {
                throw ValidationException::withMessages(['parcels' => 'Choose at least one parcel the money covers, or reject the payment.']);
            }

            $acceptedDue = (int) $accepted->sum(fn (CodRemittance $parcel): int => $parcel->dueToZefanyaMinor());
            if ($receivedMinor !== $acceptedDue) {
                throw ValidationException::withMessages([
                    'received_amount' => 'The amount received must equal the chosen parcels ('.$this->peso($acceptedDue).'). Untick the parcels the money does not cover, or reject this payment with the reason.',
                ]);
            }

            // Parcels the money did not cover go back to the company as still due.
            $leftOut = $parcels->diff($accepted);
            if ($leftOut->isNotEmpty()) {
                CodRemittance::query()->whereKey($leftOut->modelKeys())->update(['logistics_remittance_id' => null]);
            }

            $locked->forceFill([
                'status' => LogisticsRemittance::CONFIRMED,
                'received_amount_minor' => $receivedMinor,
                'short_minor' => $locked->amount_minor - $receivedMinor,
                'left_out_count' => $leftOut->count(),
                'decided_by_admin_id' => $admin->getKey(),
                'confirmed_at' => now(),
            ])->save();

            CodRemittance::query()->where('logistics_remittance_id', $locked->getKey())
                ->update(['zefanya_received_at' => $locked->confirmed_at]);

            OperationsAuditLog::record(
                $admin, 'logistics_remittance_confirmed', $locked, 'Payment #'.$locked->getKey(),
                ['status' => 'Submitted', 'received' => null],
                ['status' => 'Confirmed', 'received' => $this->peso($receivedMinor)]
                    + ($leftOut->isEmpty() ? [] : ['short' => $this->peso($locked->short_minor), 'parcels_due_again' => $leftOut->pluck('seller_order_id')->implode(', ')]),
            );

            return true;
        }, 3);

        if ($confirmed) {
            $this->tellCompany($remittance);
        }

        return $confirmed;
    }

    /** The money did not arrive as stated, so its parcels become due again. */
    public function reject(LogisticsRemittance $remittance, Admin $admin, string $reason): void
    {
        $this->authorizeAdmin($admin);

        DB::transaction(function () use ($remittance, $admin, $reason): void {
            $locked = LogisticsRemittance::query()->whereKey($remittance->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== LogisticsRemittance::SUBMITTED) {
                throw StaleStateException::field('remittance', 'Only a submitted payment can be rejected.');
            }

            $locked->forceFill([
                'status' => LogisticsRemittance::REJECTED,
                'decided_by_admin_id' => $admin->getKey(),
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ])->save();

            CodRemittance::query()->where('logistics_remittance_id', $locked->getKey())
                ->update(['logistics_remittance_id' => null]);

            OperationsAuditLog::record(
                $admin, 'logistics_remittance_rejected', $locked, 'Payment #'.$locked->getKey(),
                ['status' => 'Submitted'], ['status' => 'Rejected'], $reason,
            );
        }, 3);

        $this->tellCompany($remittance);
    }

    /** Preserve the payout and snapshot its original allocations in the immutable audit. */
    public function reverseOriginPayout(Admin $admin, OriginSharePayout $payout, string $reason): void
    {
        $this->authorizeAdmin($admin);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:500']])->validate();

        DB::transaction(function () use ($admin, $payout, $reason): void {
            LogisticsProvider::query()->whereKey($payout->logistics_provider_id)->lockForUpdate()->firstOrFail();
            $locked = OriginSharePayout::query()->whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->reversal()->exists()) {
                throw StaleStateException::field('payout', 'This payment record has already been reversed.');
            }
            $parcels = CodRemittance::query()->where('origin_share_payout_id', $locked->getKey())
                ->with('sellerOrder:id,shipping_fee_minor,carrier_provider_id')
                ->orderBy('id')->lockForUpdate()->get();
            if ($parcels->count() !== $locked->parcel_count
                || (int) $parcels->sum(fn (CodRemittance $parcel): int => $parcel->originShareMinor()) !== $locked->amount_minor) {
                throw StaleStateException::field('payout', 'The payment allocations changed. Review this payment before correcting it.');
            }

            OperationsAuditLog::record(
                $admin, 'origin_share_reversed', $locked, 'Origin payout #'.$locked->getKey(),
                ['status' => 'Paid', 'amount_minor' => $locked->amount_minor, 'reference' => $locked->reference,
                    'paid_on' => $locked->paid_on->toDateString(), 'parcel_ids' => implode(', ', $parcels->modelKeys())],
                ['status' => 'Reversed', 'applied_amount_minor' => 0], $reason,
            );
            CodRemittance::query()->whereKey($parcels->modelKeys())->where('origin_share_payout_id', $locked->getKey())
                ->update(['origin_share_payout_id' => null]);
            $this->notifyOriginAfterCommit($locked, reversed: true);
        }, 3);
    }

    private function notifyOriginAfterCommit(OriginSharePayout $payout, bool $reversed = false): void
    {
        DB::afterCommit(fn () => $this->safely(
            fn () => $payout->provider?->notify(new OriginSharePaymentRecorded($payout, $reversed)),
        ));
    }

    private function tellCompany(LogisticsRemittance $remittance): void
    {
        $this->safely(function () use ($remittance): void {
            $fresh = $remittance->fresh('provider');
            $fresh->provider?->notify(new LogisticsPaymentDecided($fresh));
        });
    }

    /** The money record is already saved, so a mail failure must not undo the page. */
    private function safely(callable $notify): void
    {
        try {
            $notify();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** Cash the company confirmed from its riders, not yet in any payment. */
    private function readyQuery(Builder $query): Builder
    {
        return $query->where('cod_remittances.status', CodRemittance::REMITTED_TO_LOGISTICS)
            ->whereNull('cod_remittances.logistics_remittance_id')
            ->whereNull('cod_remittances.zefanya_received_at');
    }

    /** Cross-provider parcels whose cash Zefanya confirmed and whose origin share is unpaid. */
    private function originOwedQuery(Builder $query): Builder
    {
        return $this->withShipping($query)
            ->whereNotNull('seller_orders.carrier_provider_id')
            ->whereColumn('seller_orders.carrier_provider_id', '<>', 'cod_remittances.logistics_provider_id')
            ->whereNotNull('cod_remittances.zefanya_received_at')
            ->whereNull('cod_remittances.origin_share_payout_id')
            ->whereRaw(CodRemittance::ORIGIN_SHARE_SQL.' > 0');
    }

    /** Sent and confirmed under the old rule: cross-provider, with nothing allocated from the fee. */
    private function legacyQuery(Builder $query): Builder
    {
        return $this->withShipping($query)
            ->whereNotNull('seller_orders.carrier_provider_id')
            ->whereColumn('seller_orders.carrier_provider_id', '<>', 'cod_remittances.logistics_provider_id')
            ->whereNotNull('cod_remittances.zefanya_received_at')
            ->where('cod_remittances.origin_share_minor', 0)
            ->where('cod_remittances.kept_shipping_minor', 0)
            ->where('seller_orders.shipping_fee_minor', '>', 0);
    }

    /**
     * Running parcel-cash balance for the Shipping fees table, oldest parcel
     * first: + the cash once the rider hands it over, − Zefanya's part once it
     * is sent (a rejected payment puts it back). Read only.
     *
     * @return array<int, array{cashIn: bool, sentOut: bool, balanceMinor: int}> keyed by cod_remittances.id
     */
    public function parcelBalances(LogisticsProvider $provider): array
    {
        $rows = $this->withShipping(CodRemittance::query()->where('cod_remittances.logistics_provider_id', $provider->getKey()))
            ->leftJoin('logistics_remittances', 'logistics_remittances.id', '=', 'cod_remittances.logistics_remittance_id')
            ->orderBy('cod_remittances.id')
            ->get([
                'cod_remittances.id', 'cod_remittances.status', 'cod_remittances.amount_minor', 'cod_remittances.received_amount_minor',
                'logistics_remittances.status as sent_status',
            ]);
        $kept = $this->withShipping(CodRemittance::query()->where('cod_remittances.logistics_provider_id', $provider->getKey()))
            ->pluck(DB::raw(CodRemittance::KEPT_SHIPPING_SQL.' as kept'), 'cod_remittances.id');

        $balance = 0;
        $out = [];
        foreach ($rows as $row) {
            $cashIn = $row->status !== CodRemittance::PENDING_REMITTANCE;
            $sentOut = $cashIn && $row->sent_status !== null && $row->sent_status !== LogisticsRemittance::REJECTED;
            if ($cashIn) {
                $balance += (int) ($row->received_amount_minor ?? $row->amount_minor);
            }
            if ($sentOut) {
                $balance -= (int) $row->amount_minor - (int) ($kept[$row->id] ?? 0);
            }
            $out[(int) $row->id] = ['cashIn' => $cashIn, 'sentOut' => $sentOut, 'balanceMinor' => $balance];
        }

        return $out;
    }

    private function withShipping(Builder $query): Builder
    {
        return $query->join('seller_orders', 'seller_orders.id', '=', 'cod_remittances.seller_order_id');
    }

    private function authorizeAdmin(Admin $admin): void
    {
        abort_unless(in_array($admin->role, [AdminRole::Admin->value, AdminRole::SuperAdmin->value], true), 403);
    }

    private function peso(int $minor): string
    {
        return '₱'.number_format($minor / 100, 2);
    }
}
