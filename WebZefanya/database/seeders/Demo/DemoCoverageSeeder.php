<?php

namespace Database\Seeders\Demo;

use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsShippingRate;
use App\Services\Logistics\LogisticsHubService;
use Database\Seeders\DeliveryScenarioSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Geographic coverage: one operational Station Hub for every city and
 * municipality of the six demo areas, and every PSGC barangay claimed by the
 * Station Hub of its own municipality.
 *
 * Builds on DeliveryScenarioSeeder: its providers, Province Hubs and stations
 * are reused as they are, and only what is missing is added. No Barangay Hub
 * exists; coverage is the existing `areas` table, activation goes through
 * LogisticsHubService::activate() and the PSGC snapshot is the authority.
 */
class DemoCoverageSeeder extends Seeder
{
    /** @var array{hubs: int, areas: int, providers: int} */
    public array $created = ['hubs' => 0, 'areas' => 0, 'providers' => 0];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (DemoNetwork::areaKeys() as $key) {
                $provider = $this->provider($key);
                $province = $this->provinceHub($key, $provider);

                foreach (DemoNetwork::psgc()[$key]['municipalities'] as $municipality) {
                    $this->station($key, $provider, $province, $municipality);
                }

                $this->rates($provider);
            }
        });
    }

    private function provider(string $key): LogisticsProvider
    {
        $existing = DemoNetwork::provider($key);

        if ($existing !== null) {
            return $existing;
        }

        if ($key !== 'cebu') {
            throw new RuntimeException("Run DeliveryScenarioSeeder first: provider for {$key} is missing.");
        }

        $area = DemoNetwork::psgc()['cebu'];
        $cebuCity = DemoNetwork::anchors('cebu')[0];
        $this->created['providers']++;

        return LogisticsProvider::factory()->create([
            'email' => DemoNetwork::email('logisticscebu'),
            'password' => DemoNetwork::passwordHash(),
            'first_name' => 'Demo',
            'last_name' => 'Cebu Logistics',
            'business_name' => DemoNetwork::CEBU_PROVIDER['name'],
            'contact_no' => DemoNetwork::phone(5, 7),
            'province_code' => $area['province'][0],
            'province_name' => $area['province'][1],
            'municipality_code' => $cebuCity['code'],
            'municipality_name' => $cebuCity['name'],
            'barangay_code' => $cebuCity['barangays'][0][0],
            'barangay_name' => $cebuCity['barangays'][0][1],
            'street' => '1 Demo Street',
        ]);
    }

    private function provinceHub(string $key, LogisticsProvider $provider): LogisticsHub
    {
        $hub = LogisticsHub::query()->where('logistics_provider_id', $provider->id)->liveProvince()->first();

        if ($hub !== null) {
            return $hub;
        }

        $area = DemoNetwork::psgc()[$key];
        $city = DemoNetwork::anchors($key)[0];
        $this->created['hubs']++;

        return LogisticsHub::query()->create($this->location($provider, $key) + [
            'name' => DemoNetwork::CEBU_PROVIDER['hub'],
            'hub_type' => LogisticsHub::PROVINCE,
            'city_municipality_code' => $city['code'],
            'municipality' => $city['name'],
            'barangay_code' => $city['barangays'][0][0],
            'barangay_name' => $city['barangays'][0][1],
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function location(LogisticsProvider $provider, string $key): array
    {
        $area = DemoNetwork::psgc()[$key];

        return [
            'logistics_provider_id' => $provider->id,
            'region_code' => $area['region'][0],
            'region_name' => $area['region'][1],
            'province_code' => $area['province'][0],
            'province_name' => $area['province'][1],
            'street' => '1 Demo Street',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
        ];
    }

    /** @param  array{code: string, name: string, barangays: list<array{0: string, 1: string}>}  $municipality */
    private function station(string $key, LogisticsProvider $provider, LogisticsHub $province, array $municipality): void
    {
        $station = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)
            ->where('city_municipality_code', $municipality['code'])
            ->orderBy('id')
            ->first();

        if ($station === null) {
            $this->created['hubs']++;
            // Inactive until its coverage exists; activation below goes through the service.
            $station = LogisticsHub::query()->create($this->location($provider, $key) + [
                'parent_hub_id' => $province->id,
                'name' => DemoNetwork::shortName($municipality['name']).' Station Hub',
                'hub_type' => LogisticsHub::STATION,
                'city_municipality_code' => $municipality['code'],
                'municipality' => $municipality['name'],
                'barangay_code' => $municipality['barangays'][0][0],
                'barangay_name' => $municipality['barangays'][0][1],
                'building' => 'Demo Logistics Hub',
                'street' => 'Poblacion Road',
                'is_active' => false,
            ]);
        }

        $claimed = Area::query()->where('logistics_provider_id', $provider->id)
            ->where('city_municipality_code', $municipality['code'])
            ->pluck('barangay_code')->filter()->flip();

        foreach ($municipality['barangays'] as [$code, $name]) {
            if ($claimed->has($code)) {
                continue;
            }

            Area::query()->create([
                'logistics_provider_id' => $provider->id,
                'station_hub_id' => $station->id,
                'name' => $name,
                'municipality' => $municipality['name'],
                'city_municipality_code' => $municipality['code'],
                'barangay_code' => $code,
                'barangay_name' => $name,
                'coverage_validated_at' => now(),
                'is_active' => true,
            ]);
            $this->created['areas']++;
        }

        if (! $station->is_active) {
            app(LogisticsHubService::class)->activate($station, true);
        }
    }

    private function rates(LogisticsProvider $provider): void
    {
        foreach ([
            LogisticsShippingRate::SAME_MUNICIPALITY => [4900, 1500],
            LogisticsShippingRate::SAME_PROVINCE => [6900, 2000],
            LogisticsShippingRate::CROSS_PROVINCE => [19900, 5000],
        ] as $zone => [$first, $additional]) {
            LogisticsShippingRate::query()->firstOrCreate(
                ['logistics_provider_id' => $provider->id, 'zone' => $zone],
                ['first_kg_minor' => $first, 'additional_kg_minor' => $additional],
            );
        }
    }
}
