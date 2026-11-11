<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ManifestStatus;
use App\Enums\ParcelHandoffStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Services\Logistics\StuckTransferService;
use App\Services\Logistics\TransferCourierService;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Transport Overview: a read-only Admin view of hub-to-hub movement across all
 * Logistics companies. Nothing here moves a parcel, changes custody, or acts
 * for a company.
 */
class TransportOverviewController extends Controller
{
    public function index(StuckTransferService $stuck): View
    {
        $couriers = TransferCourierService::available();
        $batchRelations = ['provider:id,business_name', 'originHub:id,name', 'destinationHub:id,name'];
        $handoffRelations = ['originProvider:id,business_name', 'destinationProvider:id,business_name', 'sendingHub:id,name', 'receivingHub:id,name'];
        if ($couriers) {
            $batchRelations[] = 'transferCourier:id,name,contact_no';
            $handoffRelations[] = 'transferCourier:id,name,contact_no';
        }

        $batches = Manifest::query()->where('status', ManifestStatus::Dispatched->value)
            ->with($batchRelations)
            ->withCount(['items as pending_count' => fn (Builder $q) => $q->whereNull('received_at')->whereNull('released_at')])
            ->oldest('dispatched_at')->limit(50)->get();
        $handoffs = ParcelHandoff::query()->where('status', ParcelHandoffStatus::InTransit->value)
            ->with(array_merge($handoffRelations, ['sellerOrder:id,tracking_number']))
            ->oldest('dispatched_at')->limit(50)->get();

        $inTransit = $batches->map(fn (Manifest $m): array => [
            'reference' => 'Batch #'.$m->id, 'kind' => 'Transfer batch',
            'company' => $m->provider?->business_name ?? '—',
            'route' => ($m->originHub?->name ?? '—').' → '.($m->destinationHub?->name ?? '—'),
            'parcels' => (int) $m->pending_count,
            'courier' => $couriers ? $m->transferCourier : null,
            'vehicle' => trim(($m->transport_vehicle_type ?? '').' '.($m->transport_plate_number ?? '')),
            'dispatchedAt' => $m->dispatched_at, 'overdue' => $stuck->isOverdue($m), 'mode' => $stuck->modeFor($m),
        ])->concat($handoffs->map(fn (ParcelHandoff $h): array => [
            'reference' => 'Handoff #'.$h->id, 'kind' => 'Provider handoff',
            'company' => ($h->originProvider?->business_name ?? '—').' → '.($h->destinationProvider?->business_name ?? '—'),
            'route' => ($h->sendingHub?->name ?? '—').' → '.($h->receivingHub?->name ?? '—'),
            'parcels' => 1,
            'courier' => $couriers ? $h->transferCourier : null,
            'vehicle' => trim(($h->transport_vehicle_type ?? '').' '.($h->transport_plate_number ?? '')),
            'dispatchedAt' => $h->dispatched_at, 'overdue' => $stuck->isOverdue($h), 'mode' => $stuck->modeFor($h),
        ]))->sortBy(fn (array $row): int => $row['dispatchedAt']?->getTimestamp() ?? 0)->values();

        return view('Admin.Transport.index', [
            'inTransit' => $inTransit,
            'overdueCount' => $stuck->countFor(null),
            'thresholdHours' => StuckTransferService::thresholdHours(),
            'seaHours' => StuckTransferService::thresholdHours('SEA'),
            'airHours' => StuckTransferService::thresholdHours('AIR'),
            'awaitingRecovery' => $this->awaitingRecovery(),
            'batchCount' => Manifest::query()->where('status', ManifestStatus::Dispatched->value)->count(),
            'handoffCount' => ParcelHandoff::query()->where('status', ParcelHandoffStatus::InTransit->value)->count(),
            'couriersAvailable' => $couriers,
        ]);
    }

    /** @return Collection<int, array{reference: string, company: string, route: string, cancelledAt: mixed, reason: ?string}> */
    private function awaitingRecovery(): Collection
    {
        $rows = collect();
        if (RuntimeSchema::hasColumn('manifest_items', 'recovered_at')) {
            Manifest::query()->where('status', ManifestStatus::Cancelled->value)->whereNotNull('dispatched_at')
                ->whereHas('items', fn (Builder $q) => $q->whereNull('received_at')->whereNull('recovered_at'))
                ->with(['provider:id,business_name', 'originHub:id,name', 'destinationHub:id,name'])->latest('cancelled_at')->limit(25)->get()
                ->each(fn (Manifest $m) => $rows->push([
                    'reference' => 'Batch #'.$m->id, 'company' => $m->provider?->business_name ?? '—',
                    'route' => ($m->originHub?->name ?? '—').' → '.($m->destinationHub?->name ?? '—'),
                    'cancelledAt' => $m->cancelled_at, 'reason' => $m->cancellation_reason,
                ]));
        }
        if (RuntimeSchema::hasColumn('parcel_handoffs', 'recovered_at')) {
            ParcelHandoff::query()->where('status', ParcelHandoffStatus::Cancelled->value)->whereNotNull('dispatched_at')->whereNull('recovered_at')
                ->with(['originProvider:id,business_name', 'sendingHub:id,name', 'receivingHub:id,name'])->latest('cancelled_at')->limit(25)->get()
                ->each(fn (ParcelHandoff $h) => $rows->push([
                    'reference' => 'Handoff #'.$h->id, 'company' => $h->originProvider?->business_name ?? '—',
                    'route' => ($h->sendingHub?->name ?? '—').' → '.($h->receivingHub?->name ?? '—'),
                    'cancelledAt' => $h->cancelled_at, 'reason' => $h->cancellation_reason,
                ]));
        }

        return $rows;
    }
}
