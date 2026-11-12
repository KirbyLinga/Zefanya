<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelIncidentType;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Notifications\Incident\HubDelayAlert;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class HubDelayService
{
    public const LEVELS = [
        24 => 'Internal warning',
        48 => 'Logistics escalation',
        120 => 'Mandatory operational review',
        168 => 'Cancellation or compensation assessment',
    ];

    private const STATION_STATUSES = [
        OrderStatus::AtSortingCenter, OrderStatus::Sorted, OrderStatus::AssignedToRider, OrderStatus::DeliveryFailed,
    ];

    public function sweep(): int
    {
        $updated = 0;
        SellerOrder::query()
            ->where(function ($query): void {
                $query->where(fn ($station) => $station->whereIn('status', self::STATION_STATUSES)
                    ->whereNotNull('custody_hub_id')->whereNotNull('custody_provider_id'))
                    ->orWhereHas('incidentSet', fn ($incident) => $incident->where('type', ParcelIncidentType::HubDelay->value)
                        ->whereIn('status', [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value]));
            })
            ->select('id')->chunkById(200, function ($orders) use (&$updated): void {
                foreach ($orders as $order) {
                    $updated += (int) $this->check($order->id);
                }
            });

        return $updated;
    }

    private function check(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $order = SellerOrder::query()->lockForUpdate()->find($orderId);
            if ($order === null) {
                return false;
            }
            $case = $order->incidentSet()->where('type', ParcelIncidentType::HubDelay->value)
                ->latest('id')->lockForUpdate()->first();
            $receipt = $this->stationReceipt($order);
            $changed = false;
            if ($case?->isActive() && ($case->custody_snapshot['receipt_key'] ?? null) !== ($receipt['key'] ?? null)
                && ($receipt !== null || $this->hasDepartureAfter($order, Carbon::parse($case->custody_snapshot['waiting_since'])))) {
                $case->forceFill([
                    'status' => ParcelIncidentStatus::Resolved, 'resolution' => 'HUB_WAIT_ENDED',
                    'resolved_at' => now(), 'resolved_by_type' => 'system',
                ])->save();
                $case->events()->create([
                    'event' => 'resolved', 'label' => 'Verified hub wait ended', 'occurred_at' => now(), 'actor_type' => 'system',
                    'to_status' => ParcelIncidentStatus::Resolved->value,
                ]);
                $changed = true;
            }
            if ($receipt === null) {
                return $changed;
            }
            $level = 0;
            foreach (array_keys(self::LEVELS) as $hours) {
                if ($receipt['at']->copy()->addHours($hours)->lte(now())) {
                    $level = $hours;
                }
            }
            if ($level === 0) {
                return $changed;
            }
            $sameWait = ($case?->custody_snapshot['receipt_key'] ?? null) === $receipt['key'];
            $previous = $sameWait ? (int) $case->custody_snapshot['level'] : 0;
            if ($sameWait && ! $case->isActive()) {
                return $changed;
            }
            $hub = $order->custodyHub;
            $route = $order->parcelRoute;
            $blocker = ParcelIncidentService::movementBlockers($order)->latest('id')->first();
            $nextHubId = $route?->isCompleted() === false ? $route->currentLegDestinationHubId() : null;
            $delayReason = $blocker?->reason ?? $order->reassignment_reason;
            if ($delayReason === null && $nextHubId && ! app(RoutePlannerService::class)->isRouteFeasible($route, $route->current_leg_index)) {
                $delayReason = 'The saved transport route has no valid connection for a remaining leg.';
            }
            $snapshot = [
                'receipt_key' => $receipt['key'], 'waiting_since' => $receipt['at']->toIso8601String(),
                'hub_id' => $hub->id, 'hub_name' => $hub->name, 'provider_id' => $hub->logistics_provider_id,
                'level' => $level, 'next_hub' => $nextHubId ? LogisticsHub::query()->find($nextHubId)?->name : null,
                'delay_reason' => $delayReason,
            ];
            if ($level <= $previous) {
                // MySQL JSON objects can return their keys in a different order.
                $previousSnapshot = $case->custody_snapshot;
                ksort($previousSnapshot);
                ksort($snapshot);
                if ($snapshot !== $previousSnapshot) {
                    $case->forceFill(['custody_snapshot' => $snapshot])->save();

                    return true;
                }

                return $changed;
            }
            if ($sameWait) {
                $case->forceFill(['custody_snapshot' => $snapshot])->save();
            } else {
                $case = $order->incidentSet()->create([
                    'type' => ParcelIncidentType::HubDelay->value, 'reporter_type' => 'system',
                    'reason' => 'Parcel is waiting at a verified hub.', 'custody_snapshot' => $snapshot,
                    'happened_at' => $receipt['at'], 'blocked_leg_index' => $route?->current_leg_index,
                ]);
            }
            foreach (self::LEVELS as $hours => $label) {
                if ($hours > $previous && $hours <= $level) {
                    $case->events()->create([
                        'event' => 'hub_delay_level', 'label' => $label, 'occurred_at' => now(), 'actor_type' => 'system',
                        'metadata' => ['hours' => $hours, 'hub_id' => $hub->id],
                    ]);
                }
            }
            if ($level >= 48) {
                $order->custodyProvider?->notify(new HubDelayAlert($case, 'logistics'));
                if ($previous < 48) {
                    $order->order?->buyer?->notify(new HubDelayAlert($case, 'buyer'));
                }
            }
            if ($level >= 168 && $previous < 168) {
                Admin::query()->whereIn('role', ['admin', 'super_admin'])->each(
                    fn (Admin $admin) => $admin->notify(new HubDelayAlert($case, 'admin')),
                );
            }

            return true;
        }, 3);
    }

    public function recordReview(Incidents $incident, Admin|LogisticsProvider $actor, string $owner, string $decision): void
    {
        DB::transaction(function () use ($incident, $actor, $owner, $decision): void {
            $order = SellerOrder::query()->whereKey($incident->incident_seller_order_id)->lockForUpdate()->firstOrFail();
            $case = Incidents::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            abort_unless($case->type === ParcelIncidentType::HubDelay->value, 404);
            if ($actor instanceof Admin) {
                Gate::forUser($actor)->authorize('resolve', $case);
            } else {
                abort_unless($actor->isApproved() && $actor->archived_at === null
                    && (int) $case->custody_snapshot['provider_id'] === (int) $actor->id
                    && (int) $order->custody_provider_id === (int) $actor->id, 403);
            }
            if (! $case->isActive() || ($this->stationReceipt($order)['key'] ?? null) !== $case->custody_snapshot['receipt_key']) {
                throw ValidationException::withMessages(['resolution_note' => 'This hub wait is no longer current.']);
            }
            $kind = $actor instanceof Admin ? 'admin' : 'logistics_provider';
            $case->forceFill([
                'status' => ParcelIncidentStatus::UnderReview, 'reviewed_at' => now(), 'reviewed_by_type' => $kind,
                'reviewed_by_id' => $actor->id, 'resolution_context' => [
                    'owner' => $owner, 'decision' => $decision, 'level' => $case->custody_snapshot['level'],
                ],
            ])->save();
            $case->events()->create([
                'event' => 'hub_delay_review', 'label' => 'Hub delay review recorded', 'detail' => $owner,
                'occurred_at' => now(), 'actor_type' => $kind, 'actor_id' => $actor->id,
                'metadata' => $case->resolution_context + ['note' => $decision],
            ]);
        }, 3);
    }

    private function stationReceipt(SellerOrder $order): ?array
    {
        if (! in_array($order->getRawOriginal('status'), array_column(self::STATION_STATUSES, 'value'), true)
            || $order->hasActiveReturn() || $order->custody_hub_id === null || $order->custody_provider_id === null) {
            return null;
        }
        $hub = $order->custodyHub;
        $providerId = (int) $order->custody_provider_id;
        if ($hub === null || (int) $hub->logistics_provider_id !== $providerId) {
            return null;
        }
        $manifest = $order->manifestItems()->whereNotNull('received_at')
            ->where('received_by_type', 'logistics')->where('received_by_id', $providerId)
            ->whereHas('manifest', fn ($query) => $query->where('destination_hub_id', $hub->id)
                ->where('logistics_provider_id', $providerId)->where('direction', 'forward')->whereNotNull('dispatched_at'))
            ->reorder()->latest('received_at')->latest('id')->first();
        $handoff = $order->parcelHandoffs()->where('receiving_hub_id', $hub->id)
            ->where('destination_provider_id', $providerId)->where('direction', 'forward')
            ->where('status', ParcelHandoffStatus::Received->value)->whereNotNull('accepted_at')->whereNotNull('dispatched_at')
            ->where('received_by_type', 'logistics')->where('received_by_id', $providerId)
            ->whereNotNull('received_at')->reorder()->latest('received_at')->latest('id')->first();
        $origin = (int) $order->origin_station_hub_id === $hub->id && $order->handover_at !== null
            ? $order->statusHistories()->where('to_status', OrderStatus::AtSortingCenter->value)
                ->where('actor_type', 'logistics')->where('actor_id', $providerId)->reorder()->latest('id')->first()
            : null;
        $return = $order->isBackAtStationAfterFailure() ? $order->returned_to_station_at : null;
        $receipt = collect([
            ['key' => 'origin:'.$origin?->id, 'at' => $origin?->created_at],
            ['key' => 'manifest:'.$manifest?->id, 'at' => $manifest?->received_at],
            ['key' => 'handoff:'.$handoff?->id, 'at' => $handoff?->received_at],
            ['key' => 'station-return:'.$hub->id.':'.$return?->getTimestamp(), 'at' => $return],
        ])->filter(fn ($entry) => $entry['at'] !== null)->sortByDesc('at')->first();
        if ($receipt === null || ($order->getRawOriginal('status') === OrderStatus::DeliveryFailed->value && $return === null)
            || $this->hasDepartureAfter($order, $receipt['at'])) {
            return null;
        }

        return $receipt;
    }

    private function hasDepartureAfter(SellerOrder $order, CarbonInterface $since): bool
    {
        return $order->manifestItems()->whereNull('received_at')
            ->where(fn ($query) => $query->whereHas('manifest', fn ($dispatch) => $dispatch->where('dispatched_at', '>', $since))
                ->orWhere(fn ($active) => $active->whereNull('released_at')
                    ->whereHas('manifest', fn ($dispatch) => $dispatch->where('status', ManifestStatus::Dispatched->value))))->exists()
            || $order->parcelHandoffs()->whereNull('received_at')->where(fn ($query) => $query
                ->where('dispatched_at', '>', $since)->orWhere('status', ParcelHandoffStatus::InTransit->value))->exists()
            || $order->statusHistories()->whereIn('to_status', [
                OrderStatus::OutForDelivery->value, OrderStatus::Cancelled->value, OrderStatus::Returned->value,
                OrderStatus::Delivered->value, OrderStatus::Completed->value,
            ])->where('created_at', '>', $since)->exists();
    }
}
