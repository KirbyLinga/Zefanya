<?php

namespace Tests\Support;

use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\ParcelHandoffVerification;

trait IssuesLogisticsHandoff
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withLogisticsHandoff(
        SellerOrder $parcel,
        string $action,
        array $payload = [],
        ?LogisticsProvider $provider = null,
        string $method = ParcelHandoffVerification::METHOD_MANUAL,
        ?int $stationHubId = null,
    ): array {
        $provider ??= $this->provider;
        $hubs = app(LogisticsHubService::class);
        $stationId = $stationHubId
            ?? $parcel->origin_station_hub_id
            ?? $hubs->originStationHubId((int) $provider->id, $parcel)
            ?? $hubs->assignOriginStationIfMissing($parcel->fresh() ?? $parcel, (int) $provider->id);

        $token = app(ParcelHandoffVerification::class)->issueForLogistics(
            $provider,
            $parcel->fresh() ?? $parcel,
            $action,
            $method,
            $stationId !== null ? (int) $stationId : null,
        );

        return array_merge($payload, ['verification_token' => $token]);
    }
}
