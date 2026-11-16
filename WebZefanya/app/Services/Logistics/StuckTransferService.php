<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\TransportConnection;
use App\Notifications\Admin\TransferEscalated;
use App\Notifications\Logistics\TransferOverdue;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dispatched transfers not received in time: internal transfer batches and
 * cross-provider handoffs, with a threshold per transport mode (ROAD, SEA, AIR).
 *
 * Stage 1, overdue: shown on the Dashboard and Exceptions, and the sending
 * company is told once. Stage 2, escalated: if it is still unresolved a further
 * `escalate_after_hours` later, Admin is told once.
 *
 * Read-only toward custody and route state. Overdue never means LOST: nothing
 * here moves a parcel, closes a transfer, or opens an exception. Only an
 * Admin confirmation does that.
 */
class StuckTransferService
{
    /** Hours a ROAD transfer may take (the base setting); SEA and AIR have their own. */
    public static function thresholdHours(string $mode = TransportConnection::ROAD): int
    {
        $hours = $mode === TransportConnection::ROAD
            ? config('transfers.overdue_after_hours', 24)
            : (config('transfers.overdue_hours.'.$mode) ?? config('transfers.overdue_after_hours', 24));

        return max(1, (int) $hours);
    }

    /** Further hours past overdue before Admin is told. */
    public static function escalateAfterHours(): int
    {
        return max(1, (int) config('transfers.escalate_after_hours', 24));
    }

    /** The mode a transfer travels by, from the saved connection between its two hubs; ROAD when none is saved. */
    public function modeFor(Manifest|ParcelHandoff $transfer): string
    {
        [$from, $to] = $transfer instanceof Manifest
            ? [$transfer->origin_hub_id, $transfer->destination_hub_id]
            : [$transfer->sending_hub_id, $transfer->receiving_hub_id];

        // The first active connection is the one the route planner uses.
        $mode = TransportConnection::query()->where('origin_hub_id', $from)->where('destination_hub_id', $to)
            ->where('is_active', true)->orderBy('id')->value('transport_mode');

        return in_array($mode, TransportConnection::TRANSPORT_MODES, true) ? $mode : TransportConnection::ROAD;
    }

    public function overdueAfterFor(Manifest|ParcelHandoff $transfer): int
    {
        return self::thresholdHours($this->modeFor($transfer));
    }

    public function isOverdue(Manifest|ParcelHandoff $transfer): bool
    {
        return $transfer->dispatched_at !== null && $transfer->dispatched_at->lte(now()->subHours($this->overdueAfterFor($transfer)));
    }

    public function isEscalatable(Manifest|ParcelHandoff $transfer): bool
    {
        return $transfer->dispatched_at !== null
            && $transfer->dispatched_at->lte(now()->subHours($this->overdueAfterFor($transfer) + self::escalateAfterHours()));
    }

    /** Hours of the shortest threshold: only older rows can possibly be overdue. */
    private function shortestHours(): int
    {
        return collect([TransportConnection::ROAD, TransportConnection::SEA, TransportConnection::AIR])
            ->map(fn (string $mode): int => self::thresholdHours($mode))->min();
    }

    /** @return Collection<int, Manifest> */
    public function overdueBatches(?int $providerId = null): Collection
    {
        return Manifest::query()
            ->where('status', ManifestStatus::Dispatched->value)
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '<=', now()->subHours($this->shortestHours()))
            ->when($providerId !== null, fn (Builder $q) => $q->where('logistics_provider_id', $providerId))
            ->with(['originHub', 'destinationHub', 'provider'])
            ->withCount(['items as pending_count' => fn (Builder $q) => $q->whereNull('received_at')->whereNull('released_at')])
            ->oldest('dispatched_at')->get()
            ->filter(fn (Manifest $m): bool => $this->isOverdue($m))->values();
    }

    /** Overdue handoffs; for a provider, both the ones it sent and the ones coming to it. @return Collection<int, ParcelHandoff> */
    public function overdueHandoffs(?int $providerId = null): Collection
    {
        return ParcelHandoff::query()
            ->where('status', ParcelHandoffStatus::InTransit->value)
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '<=', now()->subHours($this->shortestHours()))
            ->when($providerId !== null, fn (Builder $q) => $q->where(fn (Builder $p) => $p
                ->where('origin_provider_id', $providerId)->orWhere('destination_provider_id', $providerId)))
            ->with(['sendingHub', 'receivingHub', 'originProvider', 'destinationProvider'])
            ->oldest('dispatched_at')->get()
            ->filter(fn (ParcelHandoff $h): bool => $this->isOverdue($h))->values();
    }

    /**
     * Overdue transfers for one company (or all, for Admin), oldest first.
     *
     * @return Collection<int, array{kind: string, id: int, reference: string, route: string, mode: string, pending: int, dispatchedAt: Carbon, url: ?string, role: string}>
     */
    public function overdueFor(?int $providerId, int $limit = 10): Collection
    {
        $batches = $this->overdueBatches($providerId)->map(fn (Manifest $m): array => [
            'kind' => 'batch', 'id' => (int) $m->id, 'reference' => 'Batch #'.$m->id,
            'route' => ($m->originHub?->name ?? '—').' → '.($m->destinationHub?->name ?? '—'),
            'mode' => $this->modeFor($m), 'pending' => (int) $m->pending_count, 'dispatchedAt' => $m->dispatched_at,
            'url' => $providerId !== null ? route('logistics.manifests.show', $m) : null,
            'role' => $m->provider?->business_name ?? '',
        ]);
        $handoffs = $this->overdueHandoffs($providerId)->map(fn (ParcelHandoff $h): array => [
            'kind' => 'handoff', 'id' => (int) $h->id, 'reference' => 'Handoff #'.$h->id,
            'route' => ($h->sendingHub?->name ?? '—').' → '.($h->receivingHub?->name ?? '—'),
            'mode' => $this->modeFor($h), 'pending' => 1, 'dispatchedAt' => $h->dispatched_at,
            'url' => $providerId !== null ? route('logistics.handoffs.show', $h) : null,
            'role' => ($h->originProvider?->business_name ?? '').' → '.($h->destinationProvider?->business_name ?? ''),
        ]);

        return $batches->concat($handoffs)->sortBy(fn (array $row): int => $row['dispatchedAt']->getTimestamp())->take($limit)->values();
    }

    public function countFor(?int $providerId): int
    {
        return $this->overdueBatches($providerId)->count() + $this->overdueHandoffs($providerId)->count();
    }

    /**
     * Tell each overdue transfer's sending company once, and Admin once if it
     * stays unresolved past the escalation time. Safe to run in parallel: each
     * row is claimed under a row lock before anyone is notified.
     *
     * @return array{notified: int, escalated: int}
     */
    public function notifyOverdue(): array
    {
        $notified = 0;
        $escalated = 0;

        if (RuntimeSchema::hasColumn('manifests', 'overdue_notified_at')) {
            foreach ($this->overdueBatches()->whereNull('overdue_notified_at') as $batch) {
                $claimed = $this->claim(Manifest::query()->where('status', ManifestStatus::Dispatched->value), $batch->id, 'overdue_notified_at');
                if ($claimed instanceof Manifest) {
                    $claimed->load(['provider', 'originHub', 'destinationHub']);
                    $claimed->provider?->notify(new TransferOverdue(
                        'Transfer batch #'.$claimed->id.' is overdue',
                        'Batch #'.$claimed->id.' ('.($claimed->originHub?->name ?? 'origin').' → '.($claimed->destinationHub?->name ?? 'destination').') was dispatched over '.$this->overdueAfterFor($claimed).' hours ago and is not fully received. Your origin hub remains accountable until the receiving hub scans each parcel. Overdue does not mean lost.',
                        route('logistics.manifests.show', $claimed),
                        ['manifest_id' => $claimed->id],
                    ));
                    $notified++;
                }
            }
        }
        if (RuntimeSchema::hasColumn('parcel_handoffs', 'overdue_notified_at')) {
            foreach ($this->overdueHandoffs()->whereNull('overdue_notified_at') as $handoff) {
                $claimed = $this->claim(ParcelHandoff::query()->where('status', ParcelHandoffStatus::InTransit->value), $handoff->id, 'overdue_notified_at');
                if ($claimed instanceof ParcelHandoff) {
                    $claimed->load(['originProvider', 'destinationProvider', 'sendingHub', 'receivingHub']);
                    $claimed->originProvider?->notify(new TransferOverdue(
                        'Handoff #'.$claimed->id.' is overdue',
                        'Handoff #'.$claimed->id.' to '.($claimed->destinationProvider?->business_name ?? 'the receiving company').' ('.($claimed->sendingHub?->name ?? 'sending hub').' → '.($claimed->receivingHub?->name ?? 'receiving hub').') was dispatched over '.$this->overdueAfterFor($claimed).' hours ago and is not received. You keep custody until the receiving company scans it. Overdue does not mean lost.',
                        route('logistics.handoffs.show', $claimed),
                        ['parcel_handoff_id' => $claimed->id],
                    ));
                    $notified++;
                }
            }
        }

        // Stage 2: still unresolved well past overdue. Admin hears once.
        $admins = fn () => Admin::query()->whereIn('role', ['admin', 'super_admin'])->get();
        if (RuntimeSchema::hasColumn('manifests', 'overdue_escalated_at')) {
            foreach ($this->overdueBatches()->filter(fn (Manifest $m): bool => $this->isEscalatable($m))->whereNull('overdue_escalated_at') as $batch) {
                $claimed = $this->claim(Manifest::query()->where('status', ManifestStatus::Dispatched->value), $batch->id, 'overdue_escalated_at');
                if ($claimed instanceof Manifest) {
                    $claimed->load(['provider', 'originHub', 'destinationHub']);
                    $this->notifyAdmins($admins(), new TransferEscalated(
                        'Transfer batch #'.$claimed->id.' is still unresolved',
                        'Batch #'.$claimed->id.' of '.($claimed->provider?->business_name ?? 'a company').' ('.($claimed->originHub?->name ?? 'origin').' → '.($claimed->destinationHub?->name ?? 'destination').') has not been received '.(self::escalateAfterHours()).' hours after it became overdue. Nothing was changed. Review it on the Transport overview.',
                    ));
                    $escalated++;
                }
            }
        }
        if (RuntimeSchema::hasColumn('parcel_handoffs', 'overdue_escalated_at')) {
            foreach ($this->overdueHandoffs()->filter(fn (ParcelHandoff $h): bool => $this->isEscalatable($h))->whereNull('overdue_escalated_at') as $handoff) {
                $claimed = $this->claim(ParcelHandoff::query()->where('status', ParcelHandoffStatus::InTransit->value), $handoff->id, 'overdue_escalated_at');
                if ($claimed instanceof ParcelHandoff) {
                    $claimed->load(['originProvider', 'destinationProvider']);
                    $this->notifyAdmins($admins(), new TransferEscalated(
                        'Handoff #'.$claimed->id.' is still unresolved',
                        'Handoff #'.$claimed->id.' from '.($claimed->originProvider?->business_name ?? 'a company').' to '.($claimed->destinationProvider?->business_name ?? 'another').' has not been received '.(self::escalateAfterHours()).' hours after it became overdue. Nothing was changed. Review it on the Transport overview.',
                    ));
                    $escalated++;
                }
            }
        }

        return ['notified' => $notified, 'escalated' => $escalated];
    }

    /** @param  Collection<int, Admin>  $admins */
    private function notifyAdmins(Collection $admins, TransferEscalated $notification): void
    {
        foreach ($admins as $admin) {
            rescue(fn () => $admin->notify($notification), report: true);
        }
    }

    /** Claim a still-open row under a lock by stamping $column once; null if already claimed or no longer open. */
    private function claim(Builder $open, int $id, string $column): Manifest|ParcelHandoff|null
    {
        return DB::transaction(function () use ($open, $id, $column): Manifest|ParcelHandoff|null {
            $locked = $open->whereKey($id)->whereNull($column)->lockForUpdate()->first();
            if ($locked === null) {
                return null; // Received, cancelled, or already handled meanwhile.
            }
            $locked->forceFill([$column => now()])->save();

            return $locked;
        });
    }
}
