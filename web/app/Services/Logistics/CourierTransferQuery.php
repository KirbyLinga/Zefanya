<?php

namespace App\Services\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Logistics\CourierAccount;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\TransferCourier;
use App\Models\Logistics\TransferCourierAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Read-only view of the transfers WebZefanya has assigned to one Courier.
 *
 * The Courier and company come only from the signed-in account, never from
 * the request. Current and upcoming use the live assignment on the transfer;
 * history uses transfer_courier_assignments, so a Courier who was replaced
 * sees only what their own assignment row recorded, never later changes.
 * No buyer data, item list, cash amount, tracking number or scan token is
 * ever returned.
 */
class CourierTransferQuery
{
    private const MANIFEST_UPCOMING = [ManifestStatus::Draft];

    private const MANIFEST_CURRENT = [ManifestStatus::Dispatched];

    private const HANDOFF_UPCOMING = [ParcelHandoffStatus::Requested, ParcelHandoffStatus::Accepted];

    private const HANDOFF_CURRENT = [ParcelHandoffStatus::InTransit];

    /** @return array{upcoming: list<array>, current: list<array>} */
    public function active(CourierAccount $account): array
    {
        [$courierId, $providerId] = $this->identity($account);
        $limit = (int) config('courier.list_limit', 50);

        $manifests = Manifest::query()
            ->where('transfer_courier_id', $courierId)->where('logistics_provider_id', $providerId)
            ->where('transport_required', true)
            ->whereIn('status', array_map(fn ($s) => $s->value, [...self::MANIFEST_UPCOMING, ...self::MANIFEST_CURRENT]))
            ->with(['originHub:id,name', 'destinationHub:id,name'])
            ->withCount(['items' => fn ($query) => $query->where(function ($items): void {
                $items->where(function ($manifest): void {
                    $manifest->where('manifests.status', ManifestStatus::Cancelled->value)
                        ->whereNotNull('manifests.dispatched_at');
                })->orWhereNull('manifest_items.released_at')->orWhereNotNull('manifest_items.received_at');
            })])
            ->orderBy('id')->limit($limit)->get();

        $handoffs = ParcelHandoff::query()
            ->where('transfer_courier_id', $courierId)->where('origin_provider_id', $providerId)
            ->where('transport_required', true)
            ->whereIn('status', array_map(fn ($s) => $s->value, [...self::HANDOFF_UPCOMING, ...self::HANDOFF_CURRENT]))
            ->with(['sendingHub:id,name', 'receivingHub:id,name', 'destinationProvider'])
            ->orderBy('id')->limit($limit)->get();

        $upcoming = [];
        $current = [];
        foreach ($manifests as $manifest) {
            $row = $this->manifestResource($manifest, null);
            in_array($manifest->status, self::MANIFEST_CURRENT, true) ? $current[] = $row : $upcoming[] = $row;
        }
        foreach ($handoffs as $handoff) {
            $row = $this->handoffResource($handoff, null);
            in_array($handoff->status, self::HANDOFF_CURRENT, true) ? $current[] = $row : $upcoming[] = $row;
        }

        return ['upcoming' => $upcoming, 'current' => $current];
    }

    /** Every transfer this Courier was ever assigned by its company, newest assignment first. */
    public function history(CourierAccount $account, int $page): LengthAwarePaginator
    {
        [$courierId, $providerId] = $this->identity($account);

        $groups = TransferCourierAssignment::query()
            ->select(['manifest_id', 'parcel_handoff_id', DB::raw('MAX(id) as last_id')])
            ->where('transfer_courier_id', $courierId)->where('logistics_provider_id', $providerId)
            ->groupBy('manifest_id', 'parcel_handoff_id')
            ->orderByDesc('last_id')
            ->paginate((int) config('courier.history_per_page', 20), ['*'], 'page', max(1, $page));

        $rows = TransferCourierAssignment::query()->whereIn('id', $groups->getCollection()->pluck('last_id'))->get()->keyBy('id');
        $manifests = Manifest::query()->whereIn('id', $rows->pluck('manifest_id')->filter())
            ->where('logistics_provider_id', $providerId)
            ->with(['originHub:id,name', 'destinationHub:id,name'])
            ->withCount(['items' => fn ($query) => $query->where(function ($items): void {
                $items->where(function ($manifest): void {
                    $manifest->where('manifests.status', ManifestStatus::Cancelled->value)
                        ->whereNotNull('manifests.dispatched_at');
                })->orWhereNull('manifest_items.released_at')->orWhereNotNull('manifest_items.received_at');
            })])->get()->keyBy('id');
        $handoffs = ParcelHandoff::query()->whereIn('id', $rows->pluck('parcel_handoff_id')->filter())
            ->where('origin_provider_id', $providerId)
            ->with(['sendingHub:id,name', 'receivingHub:id,name', 'destinationProvider'])
            ->get()->keyBy('id');

        $groups->setCollection($groups->getCollection()->map(function ($group) use ($rows, $manifests, $handoffs, $courierId): array {
            $row = $rows[$group->last_id];
            $transfer = $row->manifest_id !== null ? ($manifests[$row->manifest_id] ?? null) : ($handoffs[$row->parcel_handoff_id] ?? null);

            return $this->historyResource($row, $transfer, $courierId);
        }));

        return $groups;
    }

    // ── resources ───────────────────────────────────────────────────────────

    private function historyResource(TransferCourierAssignment $row, Manifest|ParcelHandoff|null $transfer, int $courierId): array
    {
        $type = $row->manifest_id !== null ? 'manifest' : 'handoff';
        $stillMine = $transfer !== null && $row->replaced_at === null && (int) $transfer->transfer_courier_id === $courierId;

        if (! $stillMine) {
            // Reassigned away (or no longer visible): only what this Courier's
            // own assignment row recorded. Nothing read from the transfer.
            return [
                'type' => $type,
                'id' => (int) ($row->manifest_id ?? $row->parcel_handoff_id),
                'role' => 'reassigned_away',
                'vehicle_type' => $row->vehicle_type,
                'plate_number' => $row->plate_number,
                'assigned_at' => $row->assigned_at?->toIso8601String(),
                'replaced_at' => $row->replaced_at?->toIso8601String(),
            ];
        }

        $resource = $transfer instanceof Manifest ? $this->manifestResource($transfer, $row) : $this->handoffResource($transfer, $row);
        if ($transfer->dispatched_at === null) {
            // Never left the hub (still waiting, or cancelled/rejected before
            // dispatch): no parcel count or route detail beyond the hubs.
            unset($resource['parcel_count']);
        }

        return $resource;
    }

    private function manifestResource(Manifest $manifest, ?TransferCourierAssignment $row): array
    {
        return [
            'type' => 'manifest',
            'id' => (int) $manifest->id,
            'status' => $manifest->status->value,
            'role' => 'assigned',
            'origin' => ['hub_id' => (int) $manifest->origin_hub_id, 'name' => $manifest->originHub?->name],
            'destination' => ['hub_id' => (int) $manifest->destination_hub_id, 'name' => $manifest->destinationHub?->name],
            'parcel_count' => (int) $manifest->items_count,
            'vehicle_type' => $row?->vehicle_type ?? $manifest->transport_vehicle_type,
            'plate_number' => $row?->plate_number ?? $manifest->transport_plate_number,
            'assigned_at' => ($row?->assigned_at ?? $manifest->transport_assigned_at)?->toIso8601String(),
            'dispatched_at' => $manifest->dispatched_at?->toIso8601String(),
            'received_at' => $manifest->received_at?->toIso8601String(),
        ];
    }

    private function handoffResource(ParcelHandoff $handoff, ?TransferCourierAssignment $row): array
    {
        $receiver = $handoff->destinationProvider;

        return [
            'type' => 'handoff',
            'id' => (int) $handoff->id,
            'status' => $handoff->status->value,
            'role' => 'assigned',
            'origin' => ['hub_id' => (int) $handoff->sending_hub_id, 'name' => $handoff->sendingHub?->name],
            'destination' => [
                'hub_id' => (int) $handoff->receiving_hub_id,
                'name' => $handoff->receivingHub?->name,
                'provider' => $receiver ? ($receiver->business_name ?: $receiver->fullName()) : null,
            ],
            'parcel_count' => 1,
            'vehicle_type' => $row?->vehicle_type ?? $handoff->transport_vehicle_type,
            'plate_number' => $row?->plate_number ?? $handoff->transport_plate_number,
            'assigned_at' => ($row?->assigned_at ?? $handoff->transport_assigned_at)?->toIso8601String(),
            'dispatched_at' => $handoff->dispatched_at?->toIso8601String(),
            'received_at' => $handoff->received_at?->toIso8601String(),
        ];
    }

    /** @return array{0: int, 1: int} The Courier record and its company, from the account only. */
    private function identity(CourierAccount $account): array
    {
        $courier = TransferCourier::query()->findOrFail($account->transfer_courier_id);

        return [(int) $courier->id, (int) $courier->logistics_provider_id];
    }

    /** Profile fields the Courier may see about themselves. */
    public function profile(CourierAccount $account): array
    {
        $courier = TransferCourier::query()->findOrFail($account->transfer_courier_id);
        $provider = LogisticsProvider::query()->find($courier->logistics_provider_id);

        return [
            'account_id' => (int) $account->id,
            'login_id' => $account->login_id,
            'name' => $courier->name,
            'vehicle_type' => $courier->vehicle_type,
            'plate_number' => $courier->plate_number,
            'status' => $account->status,
            'provider' => ['id' => (int) $courier->logistics_provider_id, 'name' => $provider ? ($provider->business_name ?: $provider->fullName()) : null],
            'session' => (int) $account->session_generation,
        ];
    }
}
