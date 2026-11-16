<?php

namespace App\Services\Logistics;

use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\TransportConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransportConnectionService
{
    public function create(LogisticsProvider $actor, array $data): TransportConnection
    {
        return DB::transaction(function () use ($actor, $data): TransportConnection {
            // Serialize creation and activation for an origin, including concurrent requests.
            $origin = LogisticsHub::query()->lockForUpdate()->findOrFail($data['origin_hub_id']);
            abort_unless($actor->isApproved() && $actor->archived_at === null
                && (int) $origin->logistics_provider_id === (int) $actor->id, 403);
            abort_unless(! isset($data['operating_provider_id'])
                || (int) $data['operating_provider_id'] === (int) $actor->id, 403);

            return TransportConnection::query()->create([
                'origin_hub_id' => $origin->id,
                'destination_hub_id' => $data['destination_hub_id'],
                'transport_mode' => $data['transport_mode'],
                'operating_provider_id' => $actor->id,
                'is_active' => true,
            ]);
        });
    }

    public function setActive(LogisticsProvider $actor, int $id, bool $active): void
    {
        $connection = TransportConnection::query()
            ->where('operating_provider_id', $actor->id)
            ->whereHas('originHub', fn ($query) => $query->where('logistics_provider_id', $actor->id))
            ->findOrFail($id);
        DB::transaction(function () use ($actor, $connection, $active): void {
            abort_unless($actor->isApproved() && $actor->archived_at === null, 403);
            LogisticsHub::query()->whereKey($connection->origin_hub_id)->lockForUpdate()->firstOrFail();
            $connection->refresh()->update(['is_active' => $active]);
        });
    }

    public function validate(TransportConnection $connection): void
    {
        foreach (['origin_hub_id', 'destination_hub_id'] as $field) {
            $hub = LogisticsHub::query()->with(['provider', 'parent'])->find($connection->{$field});
            if (! $hub || ! $this->eligibleHub($hub)) {
                throw ValidationException::withMessages([$field => "Select an operational hub in its provider's verified province."]);
            }
        }
        $origin = LogisticsHub::query()->find($connection->origin_hub_id);
        $destination = LogisticsHub::query()->find($connection->destination_hub_id);
        if ((int) $origin->id === (int) $destination->id) {
            throw ValidationException::withMessages(['destination_hub_id' => 'A connection must join different hubs.']);
        }
        if (! in_array($connection->transport_mode, TransportConnection::TRANSPORT_MODES, true)) {
            throw ValidationException::withMessages(['transport_mode' => 'Select ROAD, SEA, or AIR.']);
        }
        if ((int) $connection->operating_provider_id !== (int) $origin->logistics_provider_id) {
            throw ValidationException::withMessages(['operating_provider_id' => 'The operator must own the origin hub.']);
        }
        if ((int) $origin->logistics_provider_id !== (int) $destination->logistics_provider_id) {
            $link = LogisticsProviderLink::query()
                ->where('origin_provider_id', $origin->logistics_provider_id)
                ->where('destination_provider_id', $destination->logistics_provider_id)
                ->where('status', LogisticsProviderLink::ACTIVE)->first();
            if (! $link) {
                throw ValidationException::withMessages(['destination_hub_id' => 'An active approved directional provider partnership is required.']);
            }
            app(ProviderHandoffSchemaService::class)->validateLink($link);
        }
        if ($connection->is_active && TransportConnection::query()
            ->where('origin_hub_id', $connection->origin_hub_id)
            ->where('destination_hub_id', $connection->destination_hub_id)
            ->where('transport_mode', $connection->transport_mode)
            ->where('operating_provider_id', $connection->operating_provider_id)
            ->where('is_active', true)
            ->when($connection->exists, fn ($query) => $query->whereKeyNot($connection->id))
            ->exists()) {
            throw ValidationException::withMessages(['destination_hub_id' => 'An active connection already exists for these endpoints, mode, and operator.']);
        }
    }

    public function eligibleHub(LogisticsHub $hub): bool
    {
        $provider = $hub->provider;
        if (! $hub->isOperational() || ! $provider || ! $provider->isApproved()
            || $provider->archived_at !== null || ! $provider->hasVerifiedProvince()
            || (string) $hub->province_code !== (string) $provider->province_code) {
            return false;
        }

        return $hub->hub_type === LogisticsHub::PROVINCE ? $hub->isLiveProvinceHub()
            : ($hub->parent?->isOperational() && $hub->parent->isLiveProvinceHub()
                && (int) $hub->parent->logistics_provider_id === (int) $provider->id
                && (string) $hub->parent->province_code === (string) $provider->province_code);
    }

    public function isUsable(TransportConnection $connection): bool
    {
        if (! $connection->is_active) {
            return false;
        }
        try {
            $this->validate($connection);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
