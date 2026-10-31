<?php

namespace Tests\Support;

use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;

/**
 * Builds a complete, assignable rider fleet for tests: an approved, active
 * Station Hub owned by the provider, active municipality coverage, an approved
 * rider linked to that station, and the Courier fleet record used by the
 * assignment pipeline.
 */
trait CreatesAssignableFleet
{
    /** @var array<int, LogisticsHub> */
    private array $fleetStations = [];

    protected function stationForProvider(LogisticsProvider $provider): LogisticsHub
    {
        return $this->fleetStations[$provider->id] ??= (function () use ($provider): LogisticsHub {
            // Reuse the provider's live Province Hub (Phase 2: one per provider).
            $province = LogisticsHub::query()
                ->where('logistics_provider_id', $provider->id)
                ->liveProvince()
                ->first();

            if ($province === null) {
                $province = LogisticsHub::query()->create([
                    'logistics_provider_id' => $provider->id,
                    'name' => 'Fleet Province Hub '.$provider->id,
                    'hub_type' => LogisticsHub::PROVINCE,
                    'region_code' => '030000000',
                    'region_name' => 'Region III',
                    'province_code' => $provider->province_code ?: '030540000',
                    'province_name' => $provider->province_name ?: 'Pampanga',
                    'is_active' => true,
                    'approval_status' => LogisticsHub::APPROVAL_APPROVED,
                    'active_province_slot' => 1,
                ]);
            }

            return LogisticsHub::query()->create([
                'logistics_provider_id' => $provider->id,
                'parent_hub_id' => $province->id,
                'name' => 'Fleet Station Hub '.$provider->id,
                'hub_type' => LogisticsHub::STATION,
                'region_code' => $province->region_code ?: '030000000',
                'region_name' => $province->region_name ?: 'Region III',
                'province_code' => $province->province_code ?: ($provider->province_code ?: '030540000'),
                'province_name' => $province->province_name ?: ($provider->province_name ?: 'Pampanga'),
                'municipality' => 'San Fernando',
                'city_municipality_code' => '030540100',
                'is_active' => true,
                'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            ]);
        })();
    }

    protected function fixtureBarangay(string $municipality): string
    {
        return match ($municipality) {
            'Quezon City' => '1374040001',
            'San Fernando' => '0305401001',
            'Angeles' => '0305402001',
            "Los Ba\u{00F1}os" => '0434110001',
            'Cebu City' => '0722170001',
            default => 'fixture-'.substr(md5($municipality), 0, 10),
        };
    }

    protected function coverMunicipality(LogisticsProvider $provider, string $municipality, ?string $code = null, ?LogisticsHub $station = null): Area
    {
        $station ??= $this->stationForProvider($provider);

        return Area::query()->firstOrCreate([
            'logistics_provider_id' => $provider->id,
            'barangay_code' => $this->fixtureBarangay($municipality),
        ], [
            'station_hub_id' => $station->id,
            'city_municipality_code' => $code,
            'municipality' => $municipality,
            'barangay_name' => 'Fixture barangay',
            'coverage_validated_at' => now(),
            'name' => $municipality,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes  Courier attribute overrides.
     */
    protected function assignableCourier(
        LogisticsProvider $provider,
        array $attributes = [],
        string $municipality = 'Quezon City',
        ?string $code = null,
    ): Courier {
        $station = $this->stationForProvider($provider);
        $area = $this->coverMunicipality($provider, $municipality, $code, $station);

        $rider = Rider::factory()->create([
            'logistics_provider_id' => $provider->id,
            'status' => Rider::STATUS_APPROVED,
            'email_verified_at' => now(),
            'station_hub_id' => $station->id,
            'vehicle_type' => $attributes['vehicle_type'] ?? 'Motorcycle',
        ]);

        return Courier::factory()->create(array_merge([
            'logistics_provider_id' => $provider->id,
            'rider_id' => $rider->id,
            'area_id' => $area->id,
            'service_area' => [$municipality],
            'is_available' => true,
            'max_weight_grams' => 10000,
        ], $attributes));
    }
}
