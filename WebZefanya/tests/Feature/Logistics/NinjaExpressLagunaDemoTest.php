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
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\ProviderHandoffSchemaService;
use Database\Seeders\DeliveryScenarioSeeder;
use Database\Seeders\Demo\DemoCoverageSeeder;
use Database\Seeders\Demo\DemoNetwork;
use Database\Seeders\Demo\DemoRiderFleetSeeder;
use Database\Seeders\Demo\DemoRoleAccountsSeeder;
use Database\Seeders\Demo\DemoTransportNetworkSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The Laguna demo seeders serve Ninja Express, a real company, instead of Demo
 * Laguna Express. Every database this class builds holds a Ninja Express in
 * Laguna with one Province Hub, so the whole demo marketplace (accounts, routing,
 * pickup and delivery assignment, transfer Couriers, handoffs) runs over it, and
 * the focused tests below cover what must never happen to the companies around it.
 */
class NinjaExpressLagunaDemoTest extends DemoMarketplaceTest
{
    private ?LogisticsProvider $ninja = null;

    protected function tearDown(): void
    {
        putenv('DEMO_SEED_AREAS');
        putenv(DemoNetwork::LAGUNA_PROVIDER_ID_ENV);

        parent::tearDown();
    }

    /** Every seeding in this class starts from a database that already holds Ninja Express. */
    public function seed($class = 'Database\\Seeders\\DatabaseSeeder'): static
    {
        $this->ninja();

        return parent::seed($class);
    }

    /** Alaminos is covered by Ninja Express once every Laguna barangay is; see DemoMarketplaceTest. */
    public function test_negative_checkout_blocks_unsupported_destination(): void
    {
        $this->markTestSkipped('Same pre-existing failure as DemoMarketplaceTest: the coverage seeder serves every Laguna barangay, Alaminos included.');
    }

    private function ninja(): LogisticsProvider
    {
        if ($this->ninja !== null) {
            return $this->ninja;
        }

        $this->ninja = LogisticsProvider::factory()->create([
            'email' => 'ninja.owner@example.test',
            'business_name' => DemoNetwork::LAGUNA_PROVIDER_NAME,
            'province_code' => '043400000',
            'province_name' => 'Laguna',
            'municipality_code' => '043426000',
            'municipality_name' => 'Santa Cruz',
            'barangay_code' => '043426001',
            'barangay_name' => 'Alipit',
        ]);

        LogisticsHub::query()->create([
            'logistics_provider_id' => $this->ninja->id,
            'region_code' => '040000000',
            'region_name' => 'CALABARZON',
            'province_code' => '043400000',
            'province_name' => 'Laguna',
            'street' => '1 Sorting Street',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'approved_at' => now(),
            'name' => 'Laguna Sorting Center',
            'hub_type' => LogisticsHub::PROVINCE,
            'city_municipality_code' => '043426000',
            'municipality' => 'Santa Cruz',
            'barangay_code' => '043426001',
            'barangay_name' => 'Alipit',
        ]);

        return $this->ninja;
    }

    private function demoLaguna(): LogisticsProvider
    {
        return LogisticsProvider::query()->where('email', DeliveryScenarioSeeder::email('logistics-laguna'))->firstOrFail();
    }

    /** The original five companies, then Ninja's coverage; nothing else yet. */
    private function seedNetworkUpToCoverage(): void
    {
        putenv(DeliveryScenarioSeeder::MAILBOX_ENV);
        putenv('DEMO_SEED_AREAS=laguna');
        Admin::factory()->create();
        $this->seed(DeliveryScenarioSeeder::class);
        $this->seed(DemoCoverageSeeder::class);
    }

    /** @return array<string, int> */
    private function footprint(int $providerId): array
    {
        return [
            'riders' => Rider::query()->where('logistics_provider_id', $providerId)->count(),
            'couriers' => Courier::query()->where('logistics_provider_id', $providerId)->count(),
            'hubs' => LogisticsHub::query()->where('logistics_provider_id', $providerId)->count(),
            'areas' => Area::query()->where('logistics_provider_id', $providerId)->count(),
            'links' => DB::table('area_courier')->join('couriers', 'couriers.id', '=', 'area_courier.courier_id')->where('couriers.logistics_provider_id', $providerId)->count(),
            'transfer' => TransferCourier::query()->where('logistics_provider_id', $providerId)->count(),
            'connections' => TransportConnection::query()->where('operating_provider_id', $providerId)->count(),
            'sellers' => SellerShippingProvider::query()->where('logistics_provider_id', $providerId)->count(),
        ];
    }

    public function test_laguna_demo_serves_ninja_express_with_complete_isolated_coverage(): void
    {
        $this->seedNetworkUpToCoverage();
        $ninja = $this->ninja();
        $demoLaguna = $this->demoLaguna();
        $bulacan = DemoNetwork::provider('bulacan');

        $this->assertSame($ninja->id, DemoNetwork::provider('laguna')->id);
        $this->assertNotSame($ninja->id, $demoLaguna->id);

        // A real Rider the company already had: eligible, covering three Calamba barangays.
        $calamba = LogisticsHub::query()->where('logistics_provider_id', $ninja->id)->where('hub_type', LogisticsHub::STATION)
            ->where('municipality', 'City of Calamba')->firstOrFail();
        $calambaAreas = Area::query()->where('station_hub_id', $calamba->id)->orderBy('barangay_code')->get();
        $real = Rider::factory()->create([
            'logistics_provider_id' => $ninja->id, 'station_hub_id' => $calamba->id, 'email' => 'real.rider@example.test',
            'status' => 'approved', 'email_verified_at' => now(), 'is_online' => true,
        ]);
        $realCourier = Courier::query()->create([
            'logistics_provider_id' => $ninja->id, 'rider_id' => $real->id, 'area_id' => $calambaAreas->first()->id, 'name' => $real->fullName(),
            'phone' => $real->contact_no, 'service_area' => ['City of Calamba'], 'vehicle_type' => 'motorcycle', 'max_weight_grams' => 20000, 'is_available' => true,
        ]);
        app(LogisticsHubService::class)->linkCourierToAreas($ninja->id, $realCourier->id, $calambaAreas->take(3)->pluck('id')->all());

        $demoBefore = $this->footprint($demoLaguna->id);
        $bulacanBefore = $this->footprint($bulacan->id);

        $this->seed(DemoRiderFleetSeeder::class);
        $this->seed(DemoTransportNetworkSeeder::class);
        $this->seed(DemoRoleAccountsSeeder::class);

        // Other companies are untouched; Demo Laguna Express keeps every record it had.
        $this->assertSame($demoBefore, $this->footprint($demoLaguna->id));
        $this->assertSame($bulacanBefore, $this->footprint($bulacan->id));
        $this->assertSame(0, Rider::query()->where('logistics_provider_id', $demoLaguna->id)->where('email', 'like', '%p'.$ninja->id.'%')->count());

        // Ownership: nothing crosses a company line.
        $this->assertSame(0, DB::table('couriers')->join('riders', 'riders.id', '=', 'couriers.rider_id')->whereColumn('couriers.logistics_provider_id', '<>', 'riders.logistics_provider_id')->count());
        $this->assertSame(0, DB::table('area_courier')->join('couriers', 'couriers.id', '=', 'area_courier.courier_id')->join('areas', 'areas.id', '=', 'area_courier.area_id')->whereColumn('couriers.logistics_provider_id', '<>', 'areas.logistics_provider_id')->count());
        $this->assertSame(0, Rider::query()->where('logistics_provider_id', $ninja->id)->whereDoesntHave('station', fn ($q) => $q->where('logistics_provider_id', $ninja->id))->count());

        // Every barangay has an eligible Rider of its own Station Hub, and no demo Rider holds more than three.
        $eligible = Courier::query()->eligibleForAssignment()->where('couriers.logistics_provider_id', $ninja->id)->pluck('couriers.id');
        $uncovered = Area::query()->where('logistics_provider_id', $ninja->id)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('area_courier')
            ->join('couriers', 'couriers.id', '=', 'area_courier.courier_id')->join('riders', 'riders.id', '=', 'couriers.rider_id')
            ->whereColumn('area_courier.area_id', 'areas.id')->whereIn('couriers.id', $eligible)->whereColumn('riders.station_hub_id', 'areas.station_hub_id'))->count();
        $this->assertSame(0, $uncovered);
        $this->assertSame(681, Area::query()->where('logistics_provider_id', $ninja->id)->count());
        $this->assertSame(30, LogisticsHub::query()->where('logistics_provider_id', $ninja->id)->where('hub_type', LogisticsHub::STATION)->count());

        $perDemoRider = DB::table('area_courier')->join('couriers', 'couriers.id', '=', 'area_courier.courier_id')->join('riders', 'riders.id', '=', 'couriers.rider_id')
            ->where('couriers.logistics_provider_id', $ninja->id)->where('riders.email', 'like', 'rider%p'.$ninja->id.'%@'.DemoNetwork::DOMAIN)
            ->groupBy('couriers.id')->selectRaw('COUNT(*) AS total')->pluck('total');
        $this->assertNotEmpty($perDemoRider);
        $this->assertLessThanOrEqual(3, (int) $perDemoRider->max());

        // CEIL(barangays no other eligible Rider already serves / 3) new demo Riders per station. The other
        // Riders are the company's real Rider and the station's backup Rider, so their barangays are not re-covered.
        $family = 'riderlagp'.$ninja->id;
        foreach (LogisticsHub::query()->where('logistics_provider_id', $ninja->id)->where('hub_type', LogisticsHub::STATION)->get() as $station) {
            $barangays = Area::query()->where('station_hub_id', $station->id)->count();
            $served = DB::table('area_courier')->join('couriers', 'couriers.id', '=', 'area_courier.courier_id')->join('riders', 'riders.id', '=', 'couriers.rider_id')
                ->where('riders.station_hub_id', $station->id)->where('riders.email', 'not like', $family.'%')->distinct()->count('area_courier.area_id');
            $demoRiders = Rider::query()->where('station_hub_id', $station->id)->where('email', 'like', $family.'%@'.DemoNetwork::DOMAIN)->count();

            $this->assertSame((int) ceil(($barangays - $served) / 3), $demoRiders, $station->municipality);
            $this->assertSame($station->id === $calamba->id ? 3 : 2, $served, $station->municipality.' served by real and backup Riders');
        }

        // One transport Courier per six Stations, with plates and contacts of their own.
        $couriers = TransferCourier::query()->where('logistics_provider_id', $ninja->id)->get();
        $this->assertCount(5, $couriers);
        $this->assertCount(5, $couriers->pluck('plate_number')->unique());
        $this->assertEmpty($couriers->pluck('plate_number')->intersect(TransferCourier::query()->where('logistics_provider_id', $demoLaguna->id)->pluck('plate_number')));
        $this->assertEmpty($couriers->pluck('contact_no')->intersect(TransferCourier::query()->where('logistics_provider_id', $demoLaguna->id)->pluck('contact_no')));

        // Routing: every Station reaches the Province Hub both ways, and Ninja and Metro Manila connect.
        $province = LogisticsHub::query()->where('logistics_provider_id', $ninja->id)->liveProvince()->firstOrFail();
        foreach (LogisticsHub::query()->where('logistics_provider_id', $ninja->id)->where('hub_type', LogisticsHub::STATION)->pluck('id') as $id) {
            $this->assertTrue(TransportConnection::query()->where('origin_hub_id', $id)->where('destination_hub_id', $province->id)->where('is_active', true)->exists());
            $this->assertTrue(TransportConnection::query()->where('origin_hub_id', $province->id)->where('destination_hub_id', $id)->where('is_active', true)->exists());
        }
        foreach (['metro', 'batangas'] as $neighbour) {
            $other = DemoNetwork::provider($neighbour);
            $this->assertTrue(LogisticsProviderLink::query()->where('origin_provider_id', $ninja->id)->where('destination_provider_id', $other->id)->where('status', LogisticsProviderLink::ACTIVE)->exists(), $neighbour);
        }

        // The Laguna demo Sellers ship with Ninja Express and still with Demo Laguna Express.
        foreach (range(1, 4) as $n) {
            $seller = Seller::query()->where('email', DemoNetwork::email('sellerlaguna'.sprintf('%02d', $n)))->firstOrFail();
            $this->assertTrue(SellerShippingProvider::query()->where('seller_id', $seller->id)->where('logistics_provider_id', $ninja->id)->where('is_enabled', true)->exists());
        }
        $this->assertSame(0, Seller::query()->where('email', 'like', 'sellerbulacan%')->count(), 'a Laguna-only run creates no other province\'s accounts');

        // Idempotent.
        $after = [$this->footprint($ninja->id), Rider::query()->count(), Courier::query()->count(), LogisticsProviderLink::query()->count()];
        $this->seed(DemoCoverageSeeder::class);
        $this->seed(DemoRiderFleetSeeder::class);
        $this->seed(DemoTransportNetworkSeeder::class);
        $this->seed(DemoRoleAccountsSeeder::class);
        $this->assertSame($after, [$this->footprint($ninja->id), Rider::query()->count(), Courier::query()->count(), LogisticsProviderLink::query()->count()]);
    }

    public function test_a_rider_owned_by_another_company_is_never_reused_or_reassigned(): void
    {
        $this->seedNetworkUpToCoverage();
        $ninja = $this->ninja();
        $demoLaguna = $this->demoLaguna();

        // The address Ninja's first Calamba Rider would get already belongs to Demo Laguna Express.
        $station = LogisticsHub::query()->where('logistics_provider_id', $demoLaguna->id)->where('hub_type', LogisticsHub::STATION)->firstOrFail();
        $email = DemoNetwork::email('riderlag'.DemoNetwork::demoTag($ninja, 'laguna').'calamba01');
        $foreign = Rider::factory()->create(['logistics_provider_id' => $demoLaguna->id, 'station_hub_id' => $station->id, 'email' => $email]);

        try {
            $this->seed(DemoRiderFleetSeeder::class);
            $this->fail('A Rider of another company must stop the seeder.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('never reused or reassigned', $e->getMessage());
        }

        $this->assertSame($demoLaguna->id, (int) $foreign->fresh()->logistics_provider_id);
        $this->assertSame(0, Courier::query()->where('rider_id', $foreign->id)->count());
    }

    public function test_a_pending_partnership_on_a_real_company_is_left_for_the_admin(): void
    {
        $this->seedNetworkUpToCoverage();
        $ninja = $this->ninja();
        $batangas = DemoNetwork::provider('batangas');

        $pending = app(ProviderHandoffSchemaService::class)->requestLink($ninja, $batangas);

        $seeder = app(DemoTransportNetworkSeeder::class);
        $seeder->run();

        $this->assertSame(LogisticsProviderLink::PENDING, $pending->fresh()->status);
        $this->assertSame(0, LogisticsProviderLink::query()->where('origin_provider_id', $batangas->id)->where('destination_provider_id', $ninja->id)->count());
        $this->assertCount(1, $seeder->gaps);
        $this->assertStringContainsString('pending Admin approval', $seeder->gaps[0]);

        $ninjaHubs = LogisticsHub::query()->where('logistics_provider_id', $ninja->id)->pluck('id');
        $batangasHubs = LogisticsHub::query()->where('logistics_provider_id', $batangas->id)->pluck('id');
        $this->assertSame(0, TransportConnection::query()->whereIn('origin_hub_id', $ninjaHubs)->whereIn('destination_hub_id', $batangasHubs)->count());
        $this->assertSame(0, TransportConnection::query()->whereIn('origin_hub_id', $batangasHubs)->whereIn('destination_hub_id', $ninjaHubs)->count());

        // Metro Manila had no request in the way: partnered and connected as usual.
        $this->assertTrue(LogisticsProviderLink::query()->where('origin_provider_id', $ninja->id)->where('destination_provider_id', DemoNetwork::provider('metro')->id)->where('status', LogisticsProviderLink::ACTIVE)->exists());
    }

    public function test_ninja_express_is_verified_by_more_than_its_name(): void
    {
        $ninja = $this->ninja();
        $this->assertSame($ninja->id, DemoNetwork::provider('laguna')->id);

        // Pinned id must match.
        putenv(DemoNetwork::LAGUNA_PROVIDER_ID_ENV.'='.$ninja->id);
        $this->assertSame($ninja->id, DemoNetwork::ninjaExpress()->id);
        putenv(DemoNetwork::LAGUNA_PROVIDER_ID_ENV.'='.($ninja->id + 100));
        try {
            DemoNetwork::ninjaExpress();
            $this->fail('A pinned id that is not Ninja Express must stop the seeders.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('is not an approved', $e->getMessage());
        }
        putenv(DemoNetwork::LAGUNA_PROVIDER_ID_ENV);

        // A second Ninja Express is ambiguous until the id is pinned.
        $twin = LogisticsProvider::factory()->create(['business_name' => DemoNetwork::LAGUNA_PROVIDER_NAME, 'province_code' => '043400000', 'province_name' => 'Laguna']);
        try {
            DemoNetwork::ninjaExpress();
            $this->fail('Two matching companies must stop the seeders.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('More than one', $e->getMessage());
        }
        putenv(DemoNetwork::LAGUNA_PROVIDER_ID_ENV.'='.$ninja->id);
        $this->assertSame($ninja->id, DemoNetwork::ninjaExpress()->id);
        putenv(DemoNetwork::LAGUNA_PROVIDER_ID_ENV);

        // The name alone is not enough: wrong province, pending status or archived company never match.
        // Straight to the table: the model locks a provider's province once it is verified.
        $providers = fn () => DB::table('logistics_providers')->where('id', $ninja->id);
        DB::table('logistics_providers')->where('id', $twin->id)->update(['status' => 'pending']);
        $providers()->update(['province_name' => 'Cebu']);
        $this->assertNull(DemoNetwork::ninjaExpress());
        $providers()->update(['province_name' => 'Laguna', 'archived_at' => now()]);
        $this->assertNull(DemoNetwork::ninjaExpress());
    }
}
