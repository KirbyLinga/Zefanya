<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelLossCase;
use App\Models\Rider\RiderEarning;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerPayoutAllocation;
use App\Models\Shared\SellerOrder;
use App\Support\RuntimeSchema;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read models only. Never call operational services or persist derived state here. */
class OrderMonitoringService
{
    public const ATTENTION = [
        'unassigned' => 'Unassigned pickup',
        'missed_pickup' => 'Missed pickup windows',
        'failed_delivery' => 'Failed delivery',
        'incident' => 'Open incident',
        'transfer' => 'Outstanding transfer',
        'handoff_exception' => 'Open handoff exception',
        'reassignment' => 'Needs reassignment',
    ];

    public const SUMMARY_FILTERS = ['active', 'pickup', 'transit', 'attention'];

    public function query(array $filters = []): Builder
    {
        $query = SellerOrder::query()->with(['order.buyer', 'seller', 'carrierProvider', 'courier', 'deliveryCourier', 'deliveryProof', 'parcelReturn.collectionCourier'])
            ->addSelect(['status_since' => DB::table('status_histories')->select('created_at')
                ->whereColumn('seller_order_id', 'seller_orders.id')->whereColumn('to_status', 'seller_orders.status')->whereColumn('from_status', '<>', 'to_status')->orderByDesc('created_at')->orderByDesc('id')->limit(1)]);
        foreach (self::ATTENTION as $key => $label) {
            // EXISTS keeps exception rows out of the result set and prevents duplicated shipments.
            $this->attentionExists($query, $key);
        }
        if ($search = trim($filters['q'] ?? '')) {
            $query->where(function (Builder $q) use ($search): void {
                if (ctype_digit($search)) {
                    $q->whereKey((int) $search)->orWhere('order_id', (int) $search);
                }
                $pattern = '%'.$search.'%';
                $q->orWhere('tracking_number', 'like', $pattern)
                    ->orWhereHas('order', fn (Builder $o) => $o->where('reference', 'like', $pattern))
                    ->orWhereHas('order.buyer', fn (Builder $b) => $b->where('first_name', 'like', $pattern)->orWhere('last_name', 'like', $pattern))
                    ->orWhereHas('seller', fn (Builder $s) => $s->where('business_name', 'like', $pattern));
            });
        }
        $query->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['provider'] ?? null, fn (Builder $q, $id) => $q->where('carrier_provider_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, string $date) => $q->where('created_at', '>=', $date.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $q, string $date) => $q->where('created_at', '<', Carbon::parse($date)->addDay()->toDateString().' 00:00:00'));
        if ($summary = $filters['summary'] ?? null) {
            $this->summaryFilter($query, $summary);
        }
        if ($attention = $filters['attention'] ?? null) {
            $this->attentionFilter($query, $attention);
        }

        return $query;
    }

    /** Platform-wide counts use the same predicates as their list filters. */
    public function summaryCounts(): array
    {
        $counts = [];
        foreach (self::SUMMARY_FILTERS as $filter) {
            $query = SellerOrder::query();
            $this->summaryFilter($query, $filter);
            $counts[$filter] = $query->count();
        }

        return $counts;
    }

    private function summaryFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            // Keep the Admin dashboard's existing in-progress definition.
            'active' => $query->whereNotIn('status', [
                OrderStatus::Delivered->value, OrderStatus::Completed->value,
                OrderStatus::Returned->value, OrderStatus::Cancelled->value, OrderStatus::Lost->value,
            ]),
            'pickup' => $query->where('status', OrderStatus::ReadyForPickup->value),
            'transit' => $query->where('status', OrderStatus::InTransit->value),
            'attention' => $query->where(function (Builder $group): void {
                foreach (array_keys(self::ATTENTION) as $indicator) {
                    $group->orWhere(fn (Builder $condition) => $this->attentionFilter($condition, $indicator));
                }
            }),
        };
    }

    private function attentionExists(Builder $query, string $key): void
    {
        match ($key) {
            'incident' => $query->withExists(['incidentSet as attention_incident' => fn (Builder $q) => $this->activeIncidents($q)]),
            'transfer' => $query->withExists([
                'manifestItems as attention_manifest' => fn (Builder $q) => $q->whereNull('received_at')->whereNull('released_at')->whereHas('manifest', fn (Builder $m) => $m->where('status', 'DISPATCHED')),
                'parcelHandoffs as attention_handoff' => fn (Builder $q) => $q->whereIn('status', ['REQUESTED', 'ACCEPTED', 'IN_TRANSIT']),
            ]),
            'handoff_exception' => $query->withExists(['parcelHandoffExceptions as attention_handoff_exception' => fn (Builder $q) => $q->where('status', 'OPEN')]),
            default => null,
        };
    }

    private function activeIncidents(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereIn('status', ['open', 'under_review'])->orWhereRaw('UPPER(resolution) = ?', ['KEEP_ON_HOLD']));
    }

    private function attentionFilter(Builder $query, string $key): void
    {
        match ($key) {
            'unassigned' => $query->where('status', OrderStatus::ReadyForPickup->value)->whereNull('courier_id'),
            'missed_pickup' => $query->where('pickup_missed_windows', '>', 0),
            'failed_delivery' => $query->where('status', OrderStatus::DeliveryFailed->value),
            'reassignment' => $query->where('needs_reassignment', true),
            'incident' => $query->whereHas('incidentSet', fn (Builder $q) => $this->activeIncidents($q)),
            'handoff_exception' => $query->whereHas('parcelHandoffExceptions', fn (Builder $q) => $q->where('status', 'OPEN')),
            'transfer' => $query->where(fn (Builder $q) => $q
                ->whereHas('manifestItems', fn (Builder $i) => $i->whereNull('received_at')->whereNull('released_at')->whereHas('manifest', fn (Builder $m) => $m->where('status', 'DISPATCHED')))
                ->orWhereHas('parcelHandoffs', fn (Builder $h) => $h->whereIn('status', ['REQUESTED', 'ACCEPTED', 'IN_TRANSIT']))),
        };
    }

    public function attention(SellerOrder $shipment): array
    {
        return array_filter(self::ATTENTION, fn (string $key) => match ($key) {
            'unassigned' => $shipment->status === OrderStatus::ReadyForPickup && $shipment->courier_id === null,
            'missed_pickup' => $shipment->pickup_missed_windows > 0,
            'failed_delivery' => $shipment->status === OrderStatus::DeliveryFailed,
            'reassignment' => (bool) $shipment->needs_reassignment,
            'incident' => (bool) $shipment->attention_incident,
            'transfer' => $shipment->attention_manifest || $shipment->attention_handoff,
            'handoff_exception' => (bool) $shipment->attention_handoff_exception,
        }, ARRAY_FILTER_USE_KEY);
    }

    /** Latest evidence per source and parcel, bounded by the paginated shipment IDs. */
    public function custody(Collection $shipments): array
    {
        $ids = $shipments->pluck('id')->all();
        $events = [];
        $append = function ($id, $at, string $holder, string $source, ?string $actor = null) use (&$events): void {
            if ($at !== null) {
                $events[$id][] = ['at' => $at, 'holder' => $holder, 'source' => $source, 'actor' => $actor];
            }
        };
        foreach (['received_at', 'recovered_at', 'verified_for_dispatch_at'] as $field) {
            foreach ($this->latestEvidence(ManifestItem::class, $ids, $field, ['manifest.originHub', 'manifest.destinationHub', 'recoveredHub']) as $item) {
                $hub = match ($field) {
                    'received_at' => $item->manifest?->destinationHub,
                    'recovered_at' => $item->recoveredHub,
                    default => $item->manifest?->originHub,
                };
                if ($hub) {
                    $prefix = match ($field) {
                        'received_at' => 'received_by', 'recovered_at' => 'recovered_by', default => 'verified_for_dispatch_by'
                    };
                    $append($item->seller_order_id, $item->$field, $hub->name, 'Manifest item #'.$item->id.' · '.$field, $this->actor($item->{$prefix.'_type'}, $item->{$prefix.'_id'}));
                }
            }
        }
        foreach (['received_at', 'recovered_at'] as $field) {
            foreach ($this->latestEvidence(ParcelHandoff::class, $ids, $field, ['receivingHub', 'sendingHub', 'destinationProvider', 'originProvider']) as $handoff) {
                $received = $field === 'received_at';
                $holder = $received ? ($handoff->receivingHub?->name ?? $handoff->destinationProvider?->business_name) : ($handoff->sendingHub?->name ?? $handoff->originProvider?->business_name);
                if ($holder) {
                    $prefix = $received ? 'received_by' : 'recovered_by';
                    $append($handoff->seller_order_id, $handoff->$field, $holder, 'Handoff #'.$handoff->id.' · '.$field, $this->actor($handoff->{$prefix.'_type'}, $handoff->{$prefix.'_id'}));
                }
            }
        }
        // A typed pickup transition plus the dedicated physical handover timestamp is
        // evidence of pickup. A generic/latest status transition is never custody proof.
        $pickups = DB::table('status_histories')->whereIn('seller_order_id', $ids)->where('to_status', 'PICKED_UP')->where('actor_type', 'rider')->whereNotNull('actor_id')
            ->selectRaw('*, ROW_NUMBER() OVER (PARTITION BY seller_order_id ORDER BY created_at DESC, id DESC) AS evidence_rank');
        $pickups = DB::query()->fromSub($pickups, 'pickup_evidence')->where('evidence_rank', 1)->get()->keyBy('seller_order_id');
        foreach ($shipments as $shipment) {
            $pickup = $pickups->get($shipment->id);
            if ($shipment->handover_at && $pickup && $shipment->handover_at->equalTo($pickup->created_at)) {
                $append($shipment->id, $shipment->handover_at, 'Pickup courier #'.$pickup->actor_id, 'Verified seller handover', $this->actor($pickup->actor_type, $pickup->actor_id, $pickup->changed_by));
            }
            if ($proof = $shipment->deliveryProof) {
                if (filled($proof->received_by)) {
                    $append($shipment->id, $proof->submitted_at, 'Delivery recipient: '.$proof->received_by, 'Delivery proof #'.$proof->id, $this->actor('rider', $proof->rider_id));
                }
            }
            if ($return = $shipment->parcelReturn) {
                $append($shipment->id, $return->collected_at, $return->collectionCourier?->name ?? 'Not yet verified (return collected; custodian not recorded)', 'Return #'.$return->id.' · collected from buyer');
                $append($shipment->id, $return->seller_received_at, 'Seller: '.($shipment->seller?->business_name ?? '#'.$shipment->seller_id), 'Return #'.$return->id.' · seller receipt');
            }
        }
        foreach ($events as $id => $rows) {
            $events[$id] = collect($rows)->sortByDesc(fn (array $row) => (string) $row['at'])->first();
        }

        return $events;
    }

    private function latestEvidence(string $model, array $ids, string $field, array $relations): Collection
    {
        if ($ids === [] || ! RuntimeSchema::hasColumn((new $model)->getTable(), $field)) {
            return collect();
        }
        $ranked = $model::query()->whereIn('seller_order_id', $ids)->whereNotNull($field)
            ->selectRaw('*, ROW_NUMBER() OVER (PARTITION BY seller_order_id ORDER BY '.$field.' DESC, id DESC) AS evidence_rank');

        return $model::query()->fromSub($ranked->toBase(), (new $model)->getTable())
            ->where('evidence_rank', 1)->with($relations)->get();
    }

    public function statusSince(SellerOrder $shipment): ?Carbon
    {
        if ($shipment->status_since !== null) {
            return Carbon::parse($shipment->status_since);
        }

        return $shipment->status === OrderStatus::Placed ? Carbon::parse($shipment->created_at) : null;
    }

    public function actor(?string $type, $id, ?string $name = null): string
    {
        return trim(($type ? ucfirst($type) : 'Actor not recorded').($id !== null ? ' #'.$id : '').($name ? ' · '.$name : ''));
    }

    /** Union before pagination preserves chronological ordering across all activity types. */
    public function activity(SellerOrder $shipment): LengthAwarePaginator
    {
        $statuses = DB::table('status_histories')->where('seller_order_id', $shipment->id)->selectRaw("id, created_at, 'status' AS kind, from_status, to_status, actor_type, actor_id, changed_by AS actor_name, source, note, NULL AS subject");
        $assignments = DB::table('delivery_assignments AS a')->leftJoin('couriers AS c', 'c.id', '=', 'a.courier_id')->where('a.seller_order_id', $shipment->id)
            ->selectRaw("a.id, a.created_at, 'assignment' AS kind, NULL AS from_status, a.role AS to_status, a.assigned_by_type AS actor_type, a.assigned_by_id AS actor_id, NULL AS actor_name, NULL AS source, a.notes AS note, c.name AS subject");
        $audits = OperationsAuditLog::query()->where('entity_type', 'seller_order')->where('entity_id', $shipment->id)->toBase()
            ->selectRaw("id, created_at, 'audit' AS kind, NULL AS from_status, action AS to_status, actor_type, actor_id, actor_name, NULL AS source, reason AS note, NULL AS subject");

        return DB::query()->fromSub($statuses->unionAll($assignments)->unionAll($audits), 'activity')->orderBy('created_at')->orderBy('kind')->orderBy('id')->paginate(30, ['*'], 'activity_page')->withQueryString();
    }

    public function financials(SellerOrder $shipment): array
    {
        $shipment->loadMissing(['payment', 'settlement', 'parcelReturn.returnCharges.provider']);
        $cod = CodRemittance::query()->where('seller_order_id', $shipment->id)->with(['provider', 'courier', 'originSharePayout'])->first();
        $cod?->setRelation('sellerOrder', $shipment);
        $batch = $cod?->logistics_remittance_id ? LogisticsRemittance::query()->find($cod->logistics_remittance_id) : null;
        $earnings = RiderEarning::query()->where('seller_order_id', $shipment->id)->with(['courier', 'payout'])->orderBy('id')->paginate(20, ['*'], 'earnings_page')->withQueryString();
        $allocations = SellerPayoutAllocation::query()->where('seller_settlement_id', $shipment->settlement?->id ?? 0)->with('payoutRequest')->orderBy('id')->paginate(20, ['*'], 'payouts_page')->withQueryString();
        $debts = SellerDebt::query()->where('seller_order_id', $shipment->id)->orderBy('id')->paginate(20, ['*'], 'debts_page')->withQueryString();
        $loss = class_exists(ParcelLossCase::class) && RuntimeSchema::hasTable('parcel_loss_cases')
            ? ParcelLossCase::query()->where('seller_order_id', $shipment->id)->with('adjustments')->first()
            : null;
        $unpaidRider = (int) RiderEarning::query()->where('seller_order_id', $shipment->id)->whereNull('rider_payout_id')->sum('amount_minor');

        return compact('cod', 'batch', 'earnings', 'allocations', 'debts', 'loss', 'unpaidRider');
    }
}
