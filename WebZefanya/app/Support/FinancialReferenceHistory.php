<?php

namespace App\Support;

use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsReturnCharge;
use App\Models\Logistics\OriginSharePayout;
use App\Models\Logistics\ParcelLossCase;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\RiderPayout;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Services\Shared\FinancialReferenceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records every payout and refund reference already in the ledgers into
 * `financial_references`, so history written before the registry is protected.
 * Used by migrations; safe to run again (a record already registered is skipped).
 *
 * Every stored reference counts, whatever the record's current status: a paid
 * settlement later reopened for reconciliation, a partly paid request, or a
 * direct payout of the remainder after partial request payments.
 */
final class FinancialReferenceHistory
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    public static function backfill(): void
    {
        (new self)->run();
    }

    private function run(): void
    {
        $morph = fn (string $class): string => (new $class)->getMorphClass();
        $reversed = fn (string $entity, string $action): array => DB::table('operations_audit_logs')
            ->where('entity_type', $entity)->where('action', $action)->pluck('entity_id')->map(fn ($id): int => (int) $id)->all();

        foreach (DB::table('seller_payout_requests')->whereNotNull('payout_reference')->orderBy('id')->get() as $r) {
            $this->add('payout', $r->payout_reference, $morph(SellerPayoutRequest::class), $r->id, 'seller:'.$r->seller_id, (int) $r->amount_minor, $r->paid_at);
        }

        // A settlement closed by a payout request carries that request's reference (the same
        // transfer). Any other settlement reference is its own payout, e.g. the direct payout
        // of what was left after partial request payments: protect it for that remainder.
        $requestPaid = DB::table('seller_payout_allocations')
            ->join('seller_payout_requests', 'seller_payout_requests.id', '=', 'seller_payout_allocations.seller_payout_request_id')
            ->where('seller_payout_requests.status', 'PAID')
            ->get(['seller_payout_allocations.seller_settlement_id as settlement_id', 'seller_payout_allocations.amount_minor', 'seller_payout_requests.payout_reference'])
            ->groupBy('settlement_id');
        foreach (DB::table('seller_settlements')->whereNotNull('payout_reference')->orderBy('id')->get() as $s) {
            $viaRequests = $requestPaid->get($s->id, collect());
            $normalized = FinancialReferenceRegistry::normalize((string) $s->payout_reference);
            if ($viaRequests->contains(fn ($a): bool => FinancialReferenceRegistry::normalize((string) $a->payout_reference) === $normalized)) {
                continue;
            }
            $direct = max(0, (int) $s->paid_amount_minor - (int) $viaRequests->sum('amount_minor'));
            $this->add('payout', $s->payout_reference, $morph(SellerSettlement::class), $s->id, 'seller:'.$s->seller_id, $direct, $s->paid_at);
        }

        $riderReversed = $reversed('rider_payout', 'rider_payout_reversed');
        foreach (DB::table('rider_payouts')->orderBy('id')->get() as $p) {
            $this->add('payout', $p->reference, $morph(RiderPayout::class), $p->id, 'courier:'.$p->courier_id, (int) $p->amount_minor, $p->created_at, in_array((int) $p->id, $riderReversed, true));
        }
        $originReversed = $reversed('origin_share_payout', 'origin_share_reversed');
        foreach (DB::table('origin_share_payouts')->orderBy('id')->get() as $p) {
            $this->add('payout', $p->reference, $morph(OriginSharePayout::class), $p->id, 'provider:'.$p->logistics_provider_id, (int) $p->amount_minor, $p->created_at, in_array((int) $p->id, $originReversed, true));
        }
        foreach (DB::table('cod_remittances')->whereNotNull('destination_paid_reference')->orderBy('id')->get() as $c) {
            $this->add('payout', $c->destination_paid_reference, $morph(CodRemittance::class), $c->id, null, (int) $c->destination_share_minor, $c->updated_at);
        }
        foreach (DB::table('parcel_loss_cases')->whereNotNull('compensation_reference')->orderBy('id')->get() as $c) {
            $this->add('payout', $c->compensation_reference, $morph(ParcelLossCase::class), $c->id, null, (int) $c->merchandise_minor, $c->compensation_paid_at);
        }
        foreach (DB::table('logistics_return_charges')->whereNotNull('settlement_reference')->orderBy('id')->get() as $c) {
            $this->add('payout', $c->settlement_reference, $morph(LogisticsReturnCharge::class), $c->id, null, (int) $c->amount_minor, $c->settled_at);
        }

        $returnType = $morph(ParcelReturn::class);
        // A voided refund keeps its reference protected, with the destination it was sent to.
        foreach (DB::table('parcel_return_events')->where('event_key', 'like', 'refund_voided%')->orderBy('id')->get() as $e) {
            $data = json_decode((string) $e->metadata, true) ?: [];
            $this->add('refund', $data['reference'] ?? null, $returnType, (int) $e->parcel_return_id, 'return:'.$e->parcel_return_id,
                isset($data['amount_minor']) ? (int) $data['amount_minor'] : null, $e->created_at, true, $data['recipient'] ?? null);
        }
        foreach (DB::table('parcel_returns')->whereNotNull('refund_reference')->orderBy('id')->get() as $r) {
            $this->add('refund', $r->refund_reference, $returnType, $r->id, 'return:'.$r->id, (int) $r->refund_amount_minor, $r->refunded_at, false, $r->refund_recipient);
        }

        $this->write();
    }

    private function add(string $scope, ?string $reference, string $type, int $id, ?string $recipient, ?int $amount, mixed $at, bool $released = false, ?string $destination = null): void
    {
        $normalized = FinancialReferenceRegistry::normalize((string) $reference);
        if ($normalized === '') {
            return;
        }
        $now = now();
        $this->rows[] = ['scope' => $scope, 'reference' => trim((string) $reference), 'normalized' => $normalized,
            'source_type' => $type, 'source_id' => $id, 'recipient' => $recipient, 'amount_minor' => $amount,
            'destination' => FinancialReferenceRegistry::normalizeDestination($destination),
            'released_at' => $released ? $now : null, 'created_at' => $at ?? $now, 'updated_at' => $now];
    }

    private function write(): void
    {
        $hasDestination = Schema::hasColumn('financial_references', 'destination');

        // One row per scope + reference holds the unique key: a live use first, else the latest.
        // Historical duplicates are kept without the key and still block reuse (see the registry).
        $holder = [];
        foreach ($this->rows as $i => $row) {
            $key = $row['scope'].'|'.$row['normalized'];
            $current = $holder[$key] ?? null;
            if ($current === null || $this->rows[$current]['released_at'] !== null) {
                $holder[$key] = $i;
            }
        }

        foreach ($this->rows as $i => $row) {
            // A use already registered for the same record is left as it is.
            $existing = DB::table('financial_references')->where('scope', $row['scope'])->where('source_type', $row['source_type'])
                ->where('source_id', $row['source_id'])->where('normalized', $row['normalized'])
                ->when($row['released_at'] !== null, fn ($q) => $q->whereNotNull('released_at'))
                ->when($row['released_at'] === null, fn ($q) => $q->whereNull('released_at'));
            if ($existing->exists()) {
                continue;
            }
            $holdsKey = ($holder[$row['scope'].'|'.$row['normalized']] ?? null) === $i && ! DB::table('financial_references')
                ->where('scope', $row['scope'])->where('active_key', $row['normalized'])->exists();
            $row['active_key'] = $holdsKey ? $row['normalized'] : null;
            if (! $hasDestination) {
                unset($row['destination']);
            }
            DB::table('financial_references')->insert($row);
        }
    }
}
