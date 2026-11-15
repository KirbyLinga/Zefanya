<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\TransportConnection;
use App\Notifications\Admin\TransferEscalated;
use App\Notifications\Logistics\TransferOverdue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

class StuckTransferService
{
    public function modeFor(Manifest|ParcelHandoff $transfer): string
    {
        [$from, $to] = $transfer instanceof Manifest
            ? [$transfer->origin_hub_id, $transfer->destination_hub_id]
            : [$transfer->sending_hub_id, $transfer->receiving_hub_id];

        return TransportConnection::query()->where('origin_hub_id', $from)
            ->where('destination_hub_id', $to)->where('is_active', true)
            ->orderBy('id')->value('transport_mode') ?? 'ROAD';
    }

    public function overdueAfterFor(Manifest|ParcelHandoff $transfer): int
    {
        return max(1, (int) config('transfers.overdue_hours.'.$this->modeFor($transfer), config('transfers.overdue_after_hours')));
    }

    public function isOverdue(Manifest|ParcelHandoff $transfer): bool
    {
        $inTransit = $transfer instanceof Manifest
            ? $transfer->status === ManifestStatus::Dispatched
            : $transfer->status === ParcelHandoffStatus::InTransit;

        return $inTransit && $transfer->dispatched_at !== null
            && $transfer->dispatched_at->lte(now()->subHours($this->overdueAfterFor($transfer)));
    }

    public function notificationStorageAvailable(): bool
    {
        $columns = ['overdue_notified_at', 'overdue_escalated_at'];

        return Schema::hasColumns('manifests', $columns) && Schema::hasColumns('parcel_handoffs', $columns);
    }

    public function sendOverdueNotices(): void
    {
        $queries = [
            Manifest::query()->where('status', ManifestStatus::Dispatched),
            ParcelHandoff::query()->where('status', ParcelHandoffStatus::InTransit),
        ];
        foreach ($queries as $query) {
            $query->where('dispatched_at', '<=', now()->subHour())
                ->where(fn ($q) => $q->whereNull('overdue_notified_at')->orWhereNull('overdue_escalated_at'))
                ->select('id')->eachById(function (Manifest|ParcelHandoff $candidate): void {
                    DB::transaction(function () use ($candidate): void {
                        $transfer = $candidate->newQuery()->whereKey($candidate->id)->lockForUpdate()->first();
                        if ($transfer !== null && $this->isOverdue($transfer)) {
                            $this->notifyForTransfer($transfer);
                        }
                    }, 3);
                });
        }
    }

    private function notifyForTransfer(Manifest|ParcelHandoff $transfer): void
    {
        if ($transfer->overdue_notified_at === null) {
            $sender = LogisticsProvider::query()->find($transfer instanceof Manifest
                ? $transfer->logistics_provider_id
                : $transfer->origin_provider_id);
            if ($sender !== null) {
                $sender->notify(new TransferOverdue($transfer));
                $transfer->forceFill(['overdue_notified_at' => now()])->save();
            }
        }

        $escalationHours = $this->overdueAfterFor($transfer) + max(1, (int) config('transfers.escalate_after_hours'));
        if ($transfer->overdue_escalated_at === null && $transfer->dispatched_at->lte(now()->subHours($escalationHours))) {
            $admins = Admin::query()->whereIn('role', ['admin', 'super_admin'])->get();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new TransferEscalated($transfer));
                $transfer->forceFill(['overdue_escalated_at' => now()])->save();
            }
        }
    }

    /** @return array{overdueTransfers: Collection, overdueTransferCount: int} */
    public function forProvider(int $provider, ?array $hubs = null): array
    {
        $manifests = Manifest::query()->where('status', ManifestStatus::Dispatched)
            ->where('logistics_provider_id', $provider)->whereNotNull('dispatched_at')
            ->when($hubs !== null, fn ($q) => $q->where(fn ($scope) => $scope->whereIn('origin_hub_id', $hubs)->orWhereIn('destination_hub_id', $hubs)))
            ->get();
        $handoffs = ParcelHandoff::query()->where('status', ParcelHandoffStatus::InTransit)
            ->whereNotNull('dispatched_at')
            ->where(function ($q) use ($provider, $hubs): void {
                $q->where(fn ($side) => $side->where('origin_provider_id', $provider)
                    ->when($hubs !== null, fn ($hub) => $hub->whereIn('sending_hub_id', $hubs)))
                    ->orWhere(fn ($side) => $side->where('destination_provider_id', $provider)
                        ->when($hubs !== null, fn ($hub) => $hub->whereIn('receiving_hub_id', $hubs)));
            })->get();
        $overdue = collect($manifests)->concat($handoffs)->filter($this->isOverdue(...))->sortBy('dispatched_at');

        return ['overdueTransfers' => $overdue->take(10), 'overdueTransferCount' => $overdue->count()];
    }
}
