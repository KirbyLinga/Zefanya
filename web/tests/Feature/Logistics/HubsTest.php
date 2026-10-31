<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\LogisticsHubService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HubsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $this->seedPsgc();
    }

    /** Seed the PSGC cache so hub submissions never hit the live API. */
    private function seedPsgc(): void
    {
        Cache::put('psgc:regions', [
            ['code' => '030000000', 'name' => 'Region III'],
        ], 3600);
        Cache::put('psgc:provinces:030000000', [
            ['code' => '030540000', 'name' => 'Pampanga'],
            ['code' => '030630000', 'name' => 'Tarlac'],
            ['code' => '030700000', 'name' => 'Bataan'],
        ], 3600);
        Cache::put('psgc:cities:030540000:030000000', [
            ['code' => '030540100', 'name' => 'San Fernando'],
            ['code' => '030540200', 'name' => 'Angeles'],
        ], 3600);
        Cache::put('psgc:cities:030630000:030000000', [
            ['code' => '030630100', 'name' => 'Tarlac City'],
            ['code' => '030630900', 'name' => 'San Fernando'],
        ], 3600);
        Cache::put('psgc:cities:030700000:030000000', [
            ['code' => '030700100', 'name' => 'Balanga'],
        ], 3600);
        foreach ([
            '030540100' => ['0305401001', 'Barangay 1'],
            '030540200' => ['0305402001', 'Barangay 2'],
            '030630100' => ['0306301001', 'Barangay 1'],
            '030630900' => ['0306309001', 'Barangay 1'],
            '030700100' => ['0307001001', 'Barangay 1'],
        ] as $city => [$barangayCode, $barangayName]) {
            Cache::put('psgc:barangays:'.$city, [
                ['code' => $barangayCode, 'name' => $barangayName],
            ], 3600);
        }
    }

    private function province(LogisticsProvider $provider, string $name = 'Central Luzon'): LogisticsHub
    {
        $codes = match ($name) {
            'Central Luzon' => ['030000000', 'Region III', '030540000', 'Pampanga'],
            'Tarlac' => ['030000000', 'Region III', '030630000', 'Tarlac'],
            'Bataan' => ['030000000', 'Region III', '030700000', 'Bataan'],
            default => [null, null, null, $name],
        };

        return LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => $name.' Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => $codes[0],
            'region_name' => $codes[1],
            'province_code' => $codes[2],
            'province_name' => $codes[3],
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);
    }

    private function station(LogisticsProvider $provider, LogisticsHub $province, string $municipality = 'San Fernando', ?string $cityCode = null, ?string $barangayCode = null): LogisticsHub
    {
        $codes = match ($municipality) {
            'San Fernando' => ['030540100', '0305401001'],
            'Angeles' => ['030540200', '0305402001'],
            'Tarlac City' => ['030630100', '0306301001'],
            'Balanga' => ['030700100', '0307001001'],
            default => [null, null],
        };

        return LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => $municipality.' Station',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $province->region_code,
            'region_name' => $province->region_name,
            'province_code' => $province->province_code,
            'province_name' => $province->province_name,
            'city_municipality_code' => $cityCode ?? $codes[0],
            'municipality' => $municipality,
            'barangay_code' => $barangayCode ?? $codes[1],
            'barangay_name' => 'Barangay 1',
            'street' => '123 Station Street',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);
    }

    private function completeSetup(LogisticsProvider $provider): LogisticsHub
    {
        $province = $this->province($provider);
        $station = $this->station($provider, $province);
        Area::query()->create([
            'logistics_provider_id' => $provider->id,
            'station_hub_id' => $station->id,
            'name' => $station->name,
            'municipality' => $station->municipality,
            'city_municipality_code' => $station->city_municipality_code,
            'is_active' => true,
            'barangay_code' => $station->barangay_code,
            'coverage_validated_at' => now(), 'barangay_name' => 'Fixture barangay',
        ]);
        Rider::factory()->create([
            'logistics_provider_id' => $provider->id,
            'status' => 'approved',
            'email_verified_at' => now(),
            'station_hub_id' => $station->id,
        ]);

        return $station;
    }

    public function test_provider_can_create_station_hubs_under_an_approved_province_hub(): void
    {
        // Province Hubs are provisioned by Admin; a provider only adds stations.
        $province = $this->province($this->provider);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.hubs.stations.store', $province), [
                'name' => 'San Fernando Station',
                'city_municipality_code' => '030540100',

                'municipality_name' => 'San Fernando',
                'barangay_code' => '0305401001',
                'barangay_name' => 'Barangay 1',
                'street' => '456 Mabini Avenue',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $station = $province->stations()->firstOrFail();
        $this->assertSame((int) $this->provider->id, (int) $station->logistics_provider_id);
        $this->assertSame((int) $province->id, (int) $station->parent_hub_id);
        $this->assertSame($province->province_name, $station->province_name);
        $this->assertSame('030540100', $station->city_municipality_code);
        $this->assertSame('San Fernando', $station->municipality);
        $this->assertSame('0305401001', $station->barangay_code);
        $this->assertSame('Barangay 1', $station->barangay_name);
        $this->assertSame(LogisticsHub::APPROVAL_PENDING, $station->approval_status);
        $this->assertFalse($station->is_active);

        Area::query()->create([
            'logistics_provider_id' => $this->provider->id, 'station_hub_id' => $station->id,
            'name' => 'San Fernando', 'municipality' => 'San Fernando', 'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001', 'is_active' => true,
            'coverage_validated_at' => now(), 'barangay_name' => 'Fixture barangay',
        ]);
        Courier::factory()->create(['logistics_provider_id' => $this->provider->id, 'area_id' => $station->areas()->value('id')]);
        $this->actingAs($this->provider, 'logistics')->get(route('logistics.hubs.index'))
            ->assertOk()->assertSee('San Fernando')->assertSee('Pending Approval')->assertSee('1 barangays')->assertSee('1 fleet riders');
    }

    public function test_province_hubs_can_no_longer_be_self_service_created(): void
    {
        // The create route is gone; only the Admin-provisioned edit path remains,
        // so a provider POST cannot mint a new Province Hub.
        $this->actingAs($this->provider, 'logistics')
            ->post('/logistics/hubs/province', ['name' => 'Rogue Province Hub'])
            ->assertStatus(405);

        $this->assertDatabaseMissing('logistics_hubs', ['name' => 'Rogue Province Hub']);
        $this->assertSame(0, LogisticsHub::query()->where('logistics_provider_id', $this->provider->id)->count());
    }

    public function test_company_cannot_add_a_station_under_another_companys_province(): void
    {
        $otherProvider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $foreignProvince = $this->province($otherProvider, 'Cebu');

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.hubs.stations.store', $foreignProvince), [
                'name' => 'Lahug Station',
                'municipality' => 'Lahug',
            ])->assertNotFound();

        $this->assertDatabaseMissing('logistics_hubs', ['name' => 'Lahug Station']);
    }

    public function test_multiple_physical_stations_can_share_a_municipality_but_coverage_is_unique(): void
    {
        $province = $this->province($this->provider);

        // Two physical Station Hubs may exist in San Fernando.
        foreach ([['North Station', '111 Rizal Street'], ['South Station', '222 Luna Street']] as [$name, $street]) {
            $this->actingAs($this->provider, 'logistics')
                ->post(route('logistics.hubs.stations.store', $province), [
                    'name' => $name,
                    'city_municipality_code' => '030540100',

                    'municipality_name' => 'San Fernando',
                    'barangay_code' => '0305401001',
                    'barangay_name' => 'Barangay 1',
                    'street' => $street,
                ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(2, $province->stations()->where('municipality', 'San Fernando')->count());

        $north = $province->stations()->where('name', 'North Station')->firstOrFail();
        $south = $province->stations()->where('name', 'South Station')->firstOrFail();
        foreach ([$north, $south] as $createdStation) {
            $createdStation->forceFill([
                'approval_status' => LogisticsHub::APPROVAL_APPROVED,
                'is_active' => true,
            ])->save();
        }

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.areas.store'), [
                'name' => 'San Fernando coverage',
                'city_municipality_code' => '030540100',
                'municipality_name' => 'San Fernando',
                'station_hub_id' => $north->id,
                'barangay_code' => '0305401001',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $area = Area::query()->where('logistics_provider_id', $this->provider->id)->sole();
        $this->assertSame((int) $north->id, (int) $area->station_hub_id);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.areas.store'), [
                'name' => 'Conflicting coverage', 'city_municipality_code' => '030540100',
                'barangay_code' => '0305401001', 'station_hub_id' => $south->id,
            ])->assertSessionHasErrors('barangay_code');
        $this->assertSame(1, Area::query()->where('logistics_provider_id', $this->provider->id)->count());
        $this->assertSame((int) $north->id, (int) $area->fresh()->station_hub_id);
    }

    public function test_distinct_municipalities_inside_one_verified_province_are_separate_coverage(): void
    {
        // Phase 2: one provider owns one province. Coverage rows are distinct by PSGC city code.
        $pampanga = $this->province($this->provider);
        $sfStation = $this->station($this->provider, $pampanga, 'San Fernando', '030540100', '0305401001');
        $angelesStation = $this->station($this->provider, $pampanga, 'Angeles', '030540200', '0305402001');

        foreach ([
            [$sfStation, '030540100', 'San Fernando'],
            [$angelesStation, '030540200', 'Angeles'],
        ] as [$station, $code, $name]) {
            $this->actingAs($this->provider, 'logistics')
                ->post(route('logistics.areas.store'), [
                    'name' => $name.' coverage',
                    'city_municipality_code' => $code,
                    'barangay_code' => $code.'1',
                    'municipality_name' => $name,
                    'station_hub_id' => $station->id,
                ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Area::query()->where('logistics_provider_id', $this->provider->id)->count());
        $this->assertDatabaseHas('areas', [
            'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001',
            'station_hub_id' => $sfStation->id,
        ]);
        $this->assertDatabaseHas('areas', [
            'city_municipality_code' => '030540200',
            'station_hub_id' => $angelesStation->id,
        ]);
    }

    public function test_coverage_municipality_must_belong_to_the_station_province(): void
    {
        $province = $this->province($this->provider);
        $station = $this->station($this->provider, $province);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.areas.store'), [
                'name' => 'Wrong province coverage',
                'city_municipality_code' => '030700100',
                'barangay_code' => '0307001001',
                'municipality_name' => 'Balanga',
                'station_hub_id' => $station->id,
            ])->assertSessionHasErrors('city_municipality_code');

        $this->assertSame(0, Area::query()->where('logistics_provider_id', $this->provider->id)->count());
    }

    public function test_coverage_update_and_removal_are_blocked_by_rider_dependencies(): void
    {
        $province = $this->province($this->provider);
        $station = $this->station($this->provider, $province);
        $otherStation = $this->station($this->provider, $province, 'Angeles');
        $area = Area::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'station_hub_id' => $station->id,
            'name' => 'San Fernando coverage',
            'municipality' => 'San Fernando',
            'city_municipality_code' => '030540100',
            'is_active' => true,
            'barangay_code' => '0305401001',
            'coverage_validated_at' => now(), 'barangay_name' => 'Fixture barangay',
        ]);
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'area_id' => $area->id,
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.areas.update', $area), [
                'name' => 'San Fernando coverage',
                'city_municipality_code' => '030540100',
                'municipality_name' => 'San Fernando',
                'station_hub_id' => $otherStation->id,
                'is_active' => 1,
                'barangay_code' => '0305401001',
            ])->assertSessionHasErrors('coverage');

        $this->assertSame((int) $station->id, (int) $area->fresh()->station_hub_id);

        $this->actingAs($this->provider, 'logistics')
            ->delete(route('logistics.areas.destroy', $area))
            ->assertRedirect()
            ->assertSessionHasErrors('coverage');

        $this->assertDatabaseHas('areas', ['id' => $area->id]);

        $this->actingAs($this->provider, 'logistics')
            ->delete(route('logistics.areas.couriers.unassign', [$area, $courier]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($courier->fresh()->area_id);

        $station->update(['is_active' => false]);

        $this->actingAs($this->provider, 'logistics')
            ->delete(route('logistics.areas.destroy', $area))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('areas', ['id' => $area->id]);
    }

    public function test_coverage_cannot_be_updated_or_removed_across_companies(): void
    {
        $otherProvider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $foreignStation = $this->station($otherProvider, $this->province($otherProvider));
        $foreignArea = Area::query()->create([
            'logistics_provider_id' => $otherProvider->id,
            'station_hub_id' => $foreignStation->id,
            'name' => 'Foreign coverage',
            'municipality' => 'San Fernando',
            'city_municipality_code' => '030540100',
            'is_active' => true,
            'barangay_code' => '0305401001',
            'coverage_validated_at' => now(), 'barangay_name' => 'Fixture barangay',
        ]);
        $ownStation = $this->station($this->provider, $this->province($this->provider));

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.areas.update', $foreignArea), [
                'name' => 'Tampered coverage',
                'city_municipality_code' => '030540100',
                'municipality_name' => 'San Fernando',
                'station_hub_id' => $ownStation->id,
                'is_active' => 1,
                'barangay_code' => '0305401001',
            ])->assertForbidden();

        $this->actingAs($this->provider, 'logistics')
            ->delete(route('logistics.areas.destroy', $foreignArea))
            ->assertForbidden();

        $this->assertDatabaseHas('areas', [
            'id' => $foreignArea->id,
            'name' => 'Foreign coverage',
            'logistics_provider_id' => $otherProvider->id,
        ]);
    }

    public function test_coverage_allows_inactive_stations_but_requires_provider_ownership(): void
    {
        $otherProvider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $foreignStation = $this->station($otherProvider, $this->province($otherProvider));

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.areas.store'), [
                'name' => 'Foreign coverage',
                'city_municipality_code' => '030540100',
                'municipality_name' => 'San Fernando',
                'station_hub_id' => $foreignStation->id,
                'barangay_code' => '0305401001',
            ])->assertSessionHasErrors('station_hub_id');

        // Coverage may be configured on an inactive Station Hub inside the verified province.
        $province = $this->province($this->provider);
        $inactive = $this->station($this->provider, $province, 'Angeles', '030540200', '0305402001');
        $inactive->update(['is_active' => false]);
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.areas.store'), [
                'name' => 'Inactive coverage',
                'city_municipality_code' => '030540200',
                'barangay_code' => '0305402001',
                'municipality_name' => 'Angeles',
                'station_hub_id' => $inactive->id,
            ])->assertSessionHasNoErrors();
    }

    public function test_dashboard_facility_filter_scopes_shipments_to_station_or_province(): void
    {
        $province = $this->province($this->provider);
        $stationA = $this->station($this->provider, $province, 'San Fernando');
        $stationB = $this->station($this->provider, $province, 'Angeles');
        $areaA = Area::query()->create([
            'logistics_provider_id' => $this->provider->id, 'station_hub_id' => $stationA->id,
            'name' => 'San Fernando coverage', 'municipality' => 'San Fernando', 'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001', 'is_active' => true,
            'coverage_validated_at' => now(), 'barangay_name' => 'Fixture barangay',
        ]);
        $areaB = Area::query()->create([
            'logistics_provider_id' => $this->provider->id, 'station_hub_id' => $stationB->id,
            'name' => 'Angeles coverage', 'municipality' => 'Angeles', 'city_municipality_code' => '030540200', 'is_active' => true,
            'barangay_code' => '0305402001',
            'coverage_validated_at' => now(), 'barangay_name' => 'Fixture barangay',
        ]);
        $courierA = Courier::factory()->create(['logistics_provider_id' => $this->provider->id, 'area_id' => $areaA->id]);
        $courierB = Courier::factory()->create(['logistics_provider_id' => $this->provider->id, 'area_id' => $areaB->id]);
        $shipmentA = SellerOrder::factory()->create(['courier_id' => $courierA->id]);
        $shipmentA->forceFill(['destination_area_id' => $areaA->id])->save();
        $shipmentB = SellerOrder::factory()->create(['courier_id' => $courierB->id]);
        $shipmentB->forceFill(['destination_area_id' => $areaB->id])->save();
        $otherProvider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $foreignCourier = Courier::factory()->create(['logistics_provider_id' => $otherProvider->id]);
        $foreignShipment = SellerOrder::factory()->create(['courier_id' => $foreignCourier->id]);

        $response = $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.dashboard', ['facility' => 'station:'.$stationA->id]))
            ->assertOk();
        $response->assertViewHas('recentParcels', fn ($parcels) => $parcels->count() === 1
            && (int) $parcels->first()->id === (int) $shipmentA->id);

        $response = $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.dashboard', ['facility' => 'province:'.$province->id]))
            ->assertOk();
        $response->assertViewHas('recentParcels', fn ($parcels) => $parcels->count() === 2
            && $parcels->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all()
                === collect([$shipmentA->id, $shipmentB->id])->map(fn ($id) => (int) $id)->sort()->values()->all());

        $response = $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.dashboard', ['facility' => 'all']))
            ->assertOk();
        $response->assertViewHas('recentParcels', fn ($parcels) => $parcels->count() === 2
            && ! $parcels->contains('id', $foreignShipment->id));

        $this->assertSame('approved', $this->provider->fresh()->status->value);
    }

    public function test_hubs_and_facilities_cannot_be_read_or_modified_across_companies(): void
    {
        $foreignProvider = LogisticsProvider::factory()->create(['status' => 'approved']);
        // Competing providers may share a province; isolation is by provider ownership.
        $foreignProvince = $this->province($foreignProvider);
        $foreignStation = $this->station($foreignProvider, $foreignProvince, 'Angeles', '030540200', '0305402001');

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.dashboard', ['facility' => 'station:'.$foreignStation->id]))
            ->assertNotFound();

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.update', $foreignStation), [
                'name' => 'Tampered', 'municipality' => 'Angeles',
            ])->assertNotFound();

        $this->assertSame('Angeles Station', $foreignStation->fresh()->name);
    }

    public function test_hub_with_active_courier_work_cannot_be_deactivated(): void
    {
        $province = $this->province($this->provider);
        $station = $this->station($this->provider, $province);
        $area = Area::query()->create([
            'logistics_provider_id' => $this->provider->id, 'station_hub_id' => $station->id,
            'name' => 'San Fernando coverage', 'municipality' => 'San Fernando', 'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001', 'is_active' => true,
            'coverage_validated_at' => now(), 'barangay_name' => 'Fixture barangay',
        ]);
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'area_id' => $area->id,
            'is_available' => true,
        ]);
        $activeShipment = SellerOrder::factory()->status(OrderStatus::OutForDelivery)->create();
        $activeShipment->forceFill(['delivery_courier_id' => $courier->id, 'destination_area_id' => $area->id])->save();

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.status', $station), ['is_active' => false])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertTrue($station->fresh()->is_active);
        $this->assertNotNull(app(LogisticsHubService::class)->deactivationBlockReason($station));
    }

    public function test_idle_station_can_be_deactivated(): void
    {
        $province = $this->province($this->provider);
        $station = $this->station($this->provider, $province);

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.status', $station), ['is_active' => false])
            ->assertRedirect()->assertSessionHas('success', 'Hub deactivated.');

        $this->assertFalse($station->fresh()->is_active);
    }

    public function test_parcel_assignment_is_blocked_until_network_setup_is_complete(): void
    {
        // Coverage lets the provider see the parcel, but the rest of the
        // checklist (approved rider) is still incomplete.
        $province = $this->province($this->provider);
        $station = $this->station($this->provider, $province);
        Area::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'station_hub_id' => $station->id,
            'name' => 'Quezon City',
            'municipality' => 'Quezon City',
            'city_municipality_code' => '137404000',
            'barangay_code' => '1374040001',
            'barangay_name' => 'Barangay 1',
            'is_active' => true,
            'coverage_validated_at' => now(),
        ]);
        $sellerOrder = SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create([
            'seller_id' => Seller::factory()->create(['barangay_code' => '1374040001'])->id,
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $sellerOrder))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(OrderStatus::ReadyForPickup, $sellerOrder->fresh()->status);
        $this->assertNull($sellerOrder->fresh()->courier_id);
        $this->assertSame('approved', $this->provider->fresh()->status->value);
    }

    public function test_delivery_assignment_is_blocked_until_network_setup_is_complete(): void
    {
        $courier = Courier::factory()->create(['logistics_provider_id' => $this->provider->id]);
        $sellerOrder = SellerOrder::factory()->status(OrderStatus::Sorted)->create();
        $sellerOrder->forceFill(['courier_id' => $courier->id])->save();

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.parcel-sorting.assignDelivery', $sellerOrder))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(OrderStatus::Sorted, $sellerOrder->fresh()->status);
        $this->assertNull($sellerOrder->fresh()->delivery_courier_id);
    }

    public function test_redelivery_assignment_is_blocked_without_mutating_the_failed_shipment(): void
    {
        $courier = Courier::factory()->create(['logistics_provider_id' => $this->provider->id, 'is_available' => false]);
        $sellerOrder = SellerOrder::factory()->status(OrderStatus::DeliveryFailed)->create();
        $sellerOrder->forceFill(['delivery_courier_id' => $courier->id])->save();

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.delivery-assignments.reschedule', $sellerOrder))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(OrderStatus::DeliveryFailed, $sellerOrder->fresh()->status);
        $this->assertSame($courier->id, (int) $sellerOrder->fresh()->delivery_courier_id);
        $this->assertFalse($courier->fresh()->is_available);
    }

    public function test_checklist_uses_hub_coverage_and_approved_rider_records(): void
    {
        $this->assertFalse(app(LogisticsHubService::class)->checklist($this->provider->id)['complete']);
        $this->completeSetup($this->provider);
        $checklist = app(LogisticsHubService::class)->checklist($this->provider->id);

        $this->assertTrue($checklist['complete']);
        $this->assertCount(4, $checklist['items']);
        $this->assertSame([true, true, true, true], array_column($checklist['items'], 'complete'));
    }

    public function test_hub_forms_require_street_address_and_keep_building_optional(): void
    {
        $province = $this->province($this->provider);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.hubs.stations.store', $province), [
                'name' => 'San Fernando Station',
                'city_municipality_code' => '030540100',
                'municipality_name' => 'San Fernando',
                'barangay_code' => '0305401001',
                'barangay_name' => 'Barangay 1',
            ])->assertSessionHasErrors(['street' => 'The street address field is required.']);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.hubs.stations.store', $province), [
                'name' => 'San Fernando Station',
                'city_municipality_code' => '030540100',

                'municipality_name' => 'San Fernando',
                'barangay_code' => '0305401001',
                'barangay_name' => 'Barangay 1',
            ])->assertSessionHasErrors(['street' => 'The street address field is required.']);

        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.hubs.stations.store', $province), [
                'name' => 'San Fernando Station',
                'city_municipality_code' => '030540100',

                'municipality_name' => 'San Fernando',
                'barangay_code' => '0305401001',
                'barangay_name' => 'Barangay 1',
                'street' => '456 Mabini Avenue',
                'building' => 'Warehouse 2',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $station = $province->stations()->firstOrFail();
        $this->assertSame('456 Mabini Avenue', $station->street);
        $this->assertSame('Warehouse 2', $station->building);

        $this->actingAs($this->provider, 'logistics')->get(route('logistics.hubs.index'))
            ->assertOk()
            ->assertSee('Street Address')
            ->assertSee('Building / Warehouse Name')
            ->assertSee('456 Mabini Avenue');
    }

    public function test_station_can_only_be_added_under_an_approved_and_active_province_hub(): void
    {
        $pending = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'name' => 'Pending Province Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'approval_status' => LogisticsHub::APPROVAL_PENDING,
            'is_active' => false,
        ]);
        $inactive = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'name' => 'Inactive Province Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => false,
        ]);

        foreach ([$pending, $inactive] as $parent) {
            $this->actingAs($this->provider, 'logistics')
                ->post(route('logistics.hubs.stations.store', $parent), [
                    'name' => 'Blocked Station',
                    'city_municipality_code' => '030540100',

                    'municipality_name' => 'San Fernando',
                    'barangay_code' => '0305401001',
                    'barangay_name' => 'Barangay 1',
                    'street' => '123 Rizal Street',
                ])->assertSessionHasErrors('parent_hub_id');
        }

        $this->assertDatabaseMissing('logistics_hubs', ['name' => 'Blocked Station']);
    }

    public function test_station_location_codes_must_belong_to_the_parent_province(): void
    {
        $province = $this->province($this->provider);

        // Balanga belongs to Bataan, not the parent Pampanga hub.
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.hubs.stations.store', $province), [
                'name' => 'Wrong Province Station',
                'city_municipality_code' => '030700100',
                'barangay_code' => '0307001001',
                'municipality_name' => 'Balanga',
                'barangay_name' => 'Barangay 1',
                'street' => '123 Rizal Street',
            ])->assertSessionHasErrors('city_municipality_code');

        // The barangay must belong to the selected municipality.
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.hubs.stations.store', $province), [
                'name' => 'Wrong Barangay Station',
                'city_municipality_code' => '030540100',

                'municipality_name' => 'San Fernando',
                'barangay_code' => '0306301001',
                'barangay_name' => 'Barangay 1',
                'street' => '123 Rizal Street',
            ])->assertSessionHasErrors('barangay_code');

        // The municipality and barangay are required.
        $this->actingAs($this->provider, 'logistics')
            ->post(route('logistics.hubs.stations.store', $province), [
                'name' => 'Missing Location Station',
                'street' => '123 Rizal Street',
            ])->assertSessionHasErrors(['city_municipality_code', 'barangay_code']);

        $this->assertSame(0, $province->stations()->count());
    }

    public function test_pending_province_hub_can_fill_missing_psgc_codes_without_being_approved(): void
    {
        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'name' => 'Legacy Pampanga Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'province_name' => 'Pampanga',
            'street' => 'Old Street',
            'approval_status' => LogisticsHub::APPROVAL_PENDING,
            'is_active' => false,
        ]);

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.update', $province), [
                'edit_hub_id' => $province->id,
                'name' => 'Legacy Pampanga Hub',
                'region_code' => '030000000',
                'province_code' => '030540000',
                'city_municipality_code' => '030540100',

                'barangay_code' => '0305401001',
                'street' => '123 Rizal Street',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $province->refresh();
        $this->assertSame('030000000', $province->region_code);
        $this->assertSame('030540000', $province->province_code);
        $this->assertSame('030540100', $province->city_municipality_code);
        $this->assertSame('0305401001', $province->barangay_code);
        $this->assertSame('San Fernando', $province->municipality);
        $this->assertSame(LogisticsHub::APPROVAL_PENDING, $province->approval_status);
        $this->assertFalse($province->is_active);
        $this->assertDatabaseCount('logistics_hub_location_changes', 0);
    }

    public function test_approved_province_location_change_waits_for_admin_and_keeps_current_location(): void
    {
        $province = $this->province($this->provider);
        $province->forceFill([
            'city_municipality_code' => '030540100',

            'municipality' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'Barangay 1',
            'street' => 'Old Street',
        ])->save();

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.update', $province), [
                'edit_hub_id' => $province->id,
                'name' => 'Central Luzon Hub',
                'region_code' => '030000000',
                'province_code' => '030540000',
                'city_municipality_code' => '030540200',
                'barangay_code' => '0305402001',
                'street' => 'New Street',
            ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $province->refresh();
        $this->assertSame('030540100', $province->city_municipality_code);
        $this->assertSame('Old Street', $province->street);
        $this->assertSame(LogisticsHub::APPROVAL_APPROVED, $province->approval_status);
        $this->assertTrue($province->is_active);

        $change = $province->locationChanges()->where('status', 'pending')->firstOrFail();
        $this->assertSame('030540200', $change->city_municipality_code);
        $this->assertSame('Angeles', $change->municipality);
        $this->assertSame('New Street', $change->street);
        $this->assertSame('pending', $change->status);

        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.hubs.index'))
            ->assertOk()
            ->assertSee('Location change awaiting Admin approval')
            ->assertSee('New Street');
    }

    public function test_approved_station_location_change_is_queued_until_admin_approval(): void
    {
        $province = $this->province($this->provider);
        $station = $this->station($this->provider, $province);

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.update', $station), [
                'edit_hub_id' => $station->id,
                'name' => $station->name,
                'city_municipality_code' => '030540200',
                'barangay_code' => '0305402001',
                'street' => 'New Station Street',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $station->refresh();
        $this->assertSame('030540100', $station->city_municipality_code);
        $this->assertSame('123 Station Street', $station->street);
        $this->assertSame(LogisticsHub::APPROVAL_APPROVED, $station->approval_status);
        $this->assertTrue($station->is_active);

        $change = $station->locationChanges()->where('status', 'pending')->firstOrFail();
        $this->assertSame('030540200', $change->city_municipality_code);
        $this->assertSame('0305402001', $change->barangay_code);
        $this->assertSame('New Station Street', $change->street);
    }

    public function test_station_location_edit_rejects_codes_outside_parent_province_or_wrong_barangay(): void
    {
        $province = $this->province($this->provider);
        $station = $this->station($this->provider, $province);

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.update', $station), [
                'edit_hub_id' => $station->id,
                'name' => $station->name,
                'city_municipality_code' => '030700100',
                'barangay_code' => '0307001001',
                'street' => 'Wrong Province Street',
            ])->assertSessionHasErrors('city_municipality_code');

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.update', $station), [
                'edit_hub_id' => $station->id,
                'name' => $station->name,
                'city_municipality_code' => '030540100',

                'barangay_code' => '0306301001',
                'street' => 'Wrong Barangay Street',
            ])->assertSessionHasErrors('barangay_code');

        $station->refresh();
        $this->assertSame('030540100', $station->city_municipality_code);
        $this->assertSame('123 Station Street', $station->street);
        $this->assertDatabaseCount('logistics_hub_location_changes', 0);
    }

    public function test_station_location_edit_requires_a_street_address(): void
    {
        $province = $this->province($this->provider);
        $station = $this->station($this->provider, $province);

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.update', $station), [
                'edit_hub_id' => $station->id,
                'name' => $station->name,
                'city_municipality_code' => '030540100',

                'barangay_code' => '0305401001',
            ])->assertSessionHasErrors('street');
    }
}
