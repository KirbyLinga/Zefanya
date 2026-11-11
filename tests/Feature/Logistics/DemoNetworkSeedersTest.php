<?php

namespace Tests\Feature\Logistics;

use App\Models\Admin\Admin;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\TransferCourier;
use App\Models\Logistics\TransportConnection;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use Database\Seeders\DeliveryScenarioSeeder;
use Database\Seeders\Demo\DemoCoverageSeeder;
use Database\Seeders\Demo\DemoNetwork;
use Database\Seeders\Demo\DemoRiderFleetSeeder;
use Database\Seeders\Demo\DemoTransportNetworkSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoNetworkSeedersTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_network_seeders_build_complete_coverage(): void
    {
        putenv(DeliveryScenarioSeeder::MAILBOX_ENV);
        Admin::factory()->create();
        $this->seed(DeliveryScenarioSeeder::class);
        $this->seed(DemoCoverageSeeder::class);
        $this->seed(DemoRiderFleetSeeder::class);
        $this->seed(DemoTransportNetworkSeeder::class);

        $this->assertEveryMunicipalityHasAStation();
        $this->assertEveryBarangayIsClaimedOnce();
        $this->assertRiderCoverage();
        $this->assertNetwork();
        $this->assertSeedingAgainChangesNothing();
    }

    private function assertEveryMunicipalityHasAStation(): void
    {
        foreach (DemoNetwork::psgc() as $key => $area) {
            $provider = DemoNetwork::provider($key);
            $this->assertSame($area['province'][0], $provider->province_code, $key);

            foreach ($area['municipalities'] as $municipality) {
                $station = LogisticsHub::query()->where('logistics_provider_id', $provider->id)
                    ->where('hub_type', LogisticsHub::STATION)->where('city_municipality_code', $municipality['code'])->first();

                $this->assertNotNull($station, $key.' '.$municipality['name']);
                $this->assertTrue($station->isOperational(), $municipality['name']);
            }
        }
    }

    private function assertEveryBarangayIsClaimedOnce(): void
    {
        foreach (DemoNetwork::psgc() as $key => $area) {
            $provider = DemoNetwork::provider($key);

            foreach ($area['municipalities'] as $municipality) {
                $areas = Area::query()->where('logistics_provider_id', $provider->id)
                    ->where('city_municipality_code', $municipality['code'])->where('is_active', true)->get();

                $this->assertSame(
                    collect($municipality['barangays'])->pluck(0)->sort()->values()->all(),
                    $areas->pluck('barangay_code')->sort()->values()->all(),
                    $municipality['name'],
                );
                $this->assertSame(1, $areas->pluck('station_hub_id')->unique()->count(), $municipality['name']);
            }
        }
    }

    private function assertRiderCoverage(): void
    {
        $this->assertSame(0, DB::table('areas')->where('is_active', true)->whereNotNull('barangay_code')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('area_courier')
                ->join('couriers', 'couriers.id', '=', 'area_courier.courier_id')
                ->join('riders', 'riders.id', '=', 'couriers.rider_id')
                ->whereColumn('area_courier.area_id', 'areas.id')
                ->where('riders.status', 'approved')->where('riders.is_online', true)
                ->whereNotNull('riders.email_verified_at')
                ->whereColumn('riders.station_hub_id', 'areas.station_hub_id'))
            ->count());

        $demoRiders = Rider::query()->where('email', 'like', 'rider%@'.DemoNetwork::DOMAIN)->pluck('id');
        $this->assertNotEmpty($demoRiders);
        $perRider = DB::table('area_courier')->join('couriers', 'couriers.id', '=', 'area_courier.courier_id')
            ->whereIn('couriers.rider_id', $demoRiders)->groupBy('couriers.rider_id')
            ->selectRaw('COUNT(*) AS total')->pluck('total');
        $this->assertLessThanOrEqual(3, (int) $perRider->max());
        $this->assertGreaterThanOrEqual(1, (int) $perRider->min());

        // A station has at least CEIL(barangays / 3) Riders.
        foreach (LogisticsHub::query()->where('hub_type', LogisticsHub::STATION)->withCount(['areas' => fn ($q) => $q->where('is_active', true)])->get() as $station) {
            $this->assertGreaterThanOrEqual(
                (int) ceil($station->areas_count / 3),
                Rider::query()->where('station_hub_id', $station->id)->count(),
                $station->name,
            );
        }
    }

    private function assertNetwork(): void
    {
        foreach (DemoNetwork::areaKeys() as $key) {
            $provider = DemoNetwork::provider($key);
            $province = LogisticsHub::query()->where('logistics_provider_id', $provider->id)->liveProvince()->firstOrFail();
            $stations = LogisticsHub::query()->where('logistics_provider_id', $provider->id)->where('hub_type', LogisticsHub::STATION)->pluck('id');

            foreach ($stations as $id) {
                $this->assertTrue(TransportConnection::query()->where('origin_hub_id', $id)->where('destination_hub_id', $province->id)->where('is_active', true)->exists());
                $this->assertTrue(TransportConnection::query()->where('origin_hub_id', $province->id)->where('destination_hub_id', $id)->where('is_active', true)->exists());
            }

            $this->assertGreaterThanOrEqual(1, TransferCourier::query()->active()->where('logistics_provider_id', $provider->id)->count());
        }

        foreach (DemoTransportNetworkSeeder::NEIGHBOURS as [$a, $b]) {
            $this->assertTrue(LogisticsProviderLink::query()->where('origin_provider_id', DemoNetwork::provider($a)->id)
                ->where('destination_provider_id', DemoNetwork::provider($b)->id)->where('status', LogisticsProviderLink::ACTIVE)->exists());
        }
    }

    private function assertSeedingAgainChangesNothing(): void
    {
        $counts = fn (): array => [
            Area::query()->count(), LogisticsHub::query()->count(), Rider::query()->count(), Courier::query()->count(),
            DB::table('area_courier')->count(), TransportConnection::query()->count(), TransferCourier::query()->count(),
            LogisticsProviderLink::query()->count(), LogisticsProvider::query()->count(),
        ];
        $before = $counts();

        $this->seed(DeliveryScenarioSeeder::class);
        $this->seed(DemoCoverageSeeder::class);
        $this->seed(DemoRiderFleetSeeder::class);
        $this->seed(DemoTransportNetworkSeeder::class);

        $this->assertSame($before, $counts());
    }
}
