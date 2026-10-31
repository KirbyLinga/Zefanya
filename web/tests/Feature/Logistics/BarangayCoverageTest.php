<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BarangayCoverageTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function station(?LogisticsProvider $provider = null): LogisticsHub
    {
        Http::preventStrayRequests();
        Cache::put('psgc:cities:030540000:030000000', [['code' => '030540100', 'name' => 'San Fernando']], 3600);
        Cache::put('psgc:barangays:030540100', [
            ['code' => '0305401001', 'name' => 'First'],
            ['code' => '0305401002', 'name' => 'Second'],
        ], 3600);
        $provider ??= LogisticsProvider::factory()->create(['status' => 'approved']);
        // One live Province Hub per provider (Phase 2). Reuse it when creating more stations.
        $province = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->liveProvince()
            ->first();
        if ($province === null) {
            $province = LogisticsHub::query()->create([
                'logistics_provider_id' => $provider->id, 'name' => 'Province', 'hub_type' => LogisticsHub::PROVINCE,
                'region_code' => '030000000', 'province_code' => '030540000', 'province_name' => 'Pampanga',
                'is_active' => true, 'approval_status' => 'approved', 'active_province_slot' => 1,
            ]);
        }

        return LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id, 'name' => 'Station', 'hub_type' => LogisticsHub::STATION,
            'parent_hub_id' => $province->id, 'municipality' => 'San Fernando',
            'region_code' => '030000000', 'province_code' => '030540000', 'province_name' => 'Pampanga', 'barangay_code' => 'physical-address-code',
            'approval_status' => 'approved', 'is_active' => false,
        ]);
    }

    private function payload(LogisticsHub $station, string $code = '0305401001'): array
    {
        return ['name' => 'Coverage', 'station_hub_id' => $station->id,
            'city_municipality_code' => '030540100', 'barangay_code' => $code];
    }

    private function cover(LogisticsHub $station, string $code = '0305401001'): Area
    {
        return (new LogisticsHubService)->saveCoverage($station->logistics_provider_id, $this->payload($station, $code));
    }

    private function courier(LogisticsHub $station, array $attributes = []): Courier
    {
        $rider = Rider::factory()->create(array_replace([
            'logistics_provider_id' => $station->logistics_provider_id, 'station_hub_id' => $station->id,
            'status' => 'approved', 'email_verified_at' => now(),
        ], $attributes));

        return Courier::factory()->create(['logistics_provider_id' => $station->logistics_provider_id,
            'rider_id' => $rider->id, 'is_available' => true, 'max_weight_grams' => 10000]);
    }

    private function parcel(OrderStatus $status, ?string $origin = '0305401001', ?string $destination = '0305401002'): SellerOrder
    {
        return SellerOrder::factory()->status($status)->create([
            'seller_id' => Seller::factory()->create(['barangay_code' => $origin])->id,
            'order_id' => Order::factory()->create(['shipping_address' => ['barangay_code' => $destination]])->id,
        ]);
    }

    public function test_multiple_barangays_and_stations_share_municipality_without_changing_physical_address(): void
    {
        $one = $this->station();
        $two = $this->station($one->provider);
        $this->actingAs($one->provider, 'logistics')->post(route('logistics.areas.store'), [
            'name' => 'Multiple', 'station_hub_id' => $one->id, 'city_municipality_code' => '030540100',
            'barangay_codes' => ['0305401001', '0305401002'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $one->areas()->count());
        $this->assertSame('physical-address-code', $one->fresh()->barangay_code);
        $this->assertSame(0, $two->areas()->count());
    }

    #[TestWith(['bad'])]
    #[TestWith([''])]
    public function test_invalid_or_missing_barangay_is_rejected(string $code): void
    {
        $station = $this->station();
        $this->actingAs($station->provider, 'logistics')->post(route('logistics.areas.store'), $this->payload($station, $code))
            ->assertSessionHasErrors('barangay_code');
        $this->assertSame(0, $station->areas()->count());
    }

    public function test_psgc_outage_never_accepts_submitted_names(): void
    {
        $station = $this->station();
        Cache::put('psgc:barangays:030540100', [], 3600);
        $this->actingAs($station->provider, 'logistics')->post(route('logistics.areas.store'),
            $this->payload($station) + ['barangay_name' => 'Invented'])->assertSessionHasErrors('barangay_code');
        $this->assertSame(0, $station->areas()->count());
    }

    public function test_conflicting_claim_is_rejected_without_reassigning_existing_area(): void
    {
        $one = $this->station();
        $area = $this->cover($one);
        $two = $this->station($one->provider);
        $this->actingAs($one->provider, 'logistics')->post(route('logistics.areas.store'), $this->payload($two))
            ->assertSessionHasErrors('barangay_code');
        $this->assertSame($one->id, $area->fresh()->station_hub_id);
    }

    public function test_database_constraint_rejects_a_competing_write_bypassing_validation(): void
    {
        $station = $this->station();
        $this->cover($station);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('areas')->insert([
            'logistics_provider_id' => $station->logistics_provider_id, 'station_hub_id' => $station->id,
            'name' => 'Racing claim', 'municipality' => 'San Fernando', 'barangay_code' => '0305401001', 'is_active' => true,
        ]);
    }

    public function test_companies_can_cover_the_same_barangay_but_cannot_edit_each_others_coverage(): void
    {
        $one = $this->station();
        $two = $this->station();
        $this->cover($one);
        $area = $this->cover($two);
        $this->actingAs($one->provider, 'logistics')->delete(route('logistics.areas.destroy', $area))->assertForbidden();
        $this->actingAs($one->provider, 'logistics')->post(route('logistics.areas.store'), $this->payload($two))
            ->assertSessionHasErrors('station_hub_id');
        $this->assertSame(2, Area::query()->count());
    }

    public function test_station_activation_requires_coverage_and_approval(): void
    {
        $station = $this->station();
        $this->actingAs($station->provider, 'logistics')->patch(route('logistics.hubs.status', $station), ['is_active' => true])
            ->assertSessionHasErrors('is_active');
        $this->assertFalse($station->fresh()->is_active);
        $this->cover($station);
        $this->actingAs($station->provider, 'logistics')->patch(route('logistics.hubs.status', $station), ['is_active' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($station->fresh()->is_active);
    }

    public function test_active_station_cannot_lose_last_coverage(): void
    {
        $station = $this->station();
        $area = $this->cover($station);
        (new LogisticsHubService)->activate($station, true);
        $this->actingAs($station->provider, 'logistics')->delete(route('logistics.areas.destroy', $area))
            ->assertSessionHasErrors('coverage');
        $this->assertModelExists($area);
    }

    public function test_pickup_and_delivery_route_to_different_stations_in_same_city(): void
    {
        $origin = $this->station();
        $destination = $this->station($origin->provider);
        $this->cover($origin);
        $deliveryArea = $this->cover($destination, '0305401002');
        $hubs = new LogisticsHubService;
        $hubs->activate($origin, true);
        $hubs->activate($destination, true);
        $pickup = $this->courier($origin);
        $delivery = $this->courier($destination);
        $assignment = new CourierAssignmentService;
        $parcel = $this->parcel(OrderStatus::ReadyForPickup);
        $this->assertSame($pickup->id, $assignment->reservePickup($parcel, $origin->provider)?->id);
        $sorted = $this->parcel(OrderStatus::Sorted);
        // The delivery leg starts only after receiving at the destination station.
        $sorted->forceFill(['custody_provider_id' => $origin->logistics_provider_id, 'custody_hub_id' => $destination->id])->save();
        $this->assertNull($assignment->assignDelivery($sorted, $origin->provider, $pickup->id));
        $this->assertSame($delivery->id, $assignment->assignDelivery($sorted, $origin->provider)?->id);
        $this->assertSame($deliveryArea->id, $sorted->fresh()->destination_area_id);
        $this->assertSame(OrderStatus::AssignedToRider, $sorted->fresh()->status);
    }

    #[TestWith(['suspended', true])]
    #[TestWith(['pending', true])]
    #[TestWith(['approved', false])]
    public function test_ineligible_rider_cannot_receive_assignments(string $status, bool $verified): void
    {
        $station = $this->station();
        $this->cover($station);
        (new LogisticsHubService)->activate($station, true);
        $courier = $this->courier($station, ['status' => $status, 'email_verified_at' => $verified ? now() : null]);
        $parcel = $this->parcel(OrderStatus::ReadyForPickup);
        $this->assertNull((new CourierAssignmentService)->reservePickup($parcel, $station->provider, $courier->id));
        $this->assertNull($parcel->fresh()->courier_id);
    }

    public function test_legacy_municipality_rows_do_not_route_new_parcels(): void
    {
        $station = $this->station();
        $station->update(['is_active' => true]);
        Area::query()->create(['logistics_provider_id' => $station->logistics_provider_id, 'station_hub_id' => $station->id,
            'name' => 'Legacy', 'municipality' => 'San Fernando', 'city_municipality_code' => '030540100', 'is_active' => true]);
        $this->courier($station);
        $parcel = $this->parcel(OrderStatus::ReadyForPickup);
        $this->assertNull((new CourierAssignmentService)->reservePickup($parcel, $station->provider));
        $this->assertDatabaseHas('areas', ['name' => 'Legacy', 'barangay_code' => null]);
    }

    public function test_missing_address_codes_block_new_assignments_even_with_coverage(): void
    {
        $station = $this->station();
        $this->cover($station);
        (new LogisticsHubService)->activate($station, true);
        $this->courier($station);
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, null, null);
        $this->assertNull((new CourierAssignmentService)->reservePickup($parcel, $station->provider));
        $this->assertNull($parcel->fresh()->courier_id);
    }

    public function test_legacy_delivery_can_continue_without_new_coverage(): void
    {
        $station = $this->station();
        $station->update(['is_active' => true]);
        $courier = $this->courier($station);
        $parcel = $this->parcel(OrderStatus::AssignedToRider, null, null);
        $parcel->forceFill(['delivery_courier_id' => $courier->id])->save();
        (new RiderOperationsService)->startDelivery($courier->rider, $parcel);
        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
    }

    public function test_concurrent_connections_produce_exactly_one_active_claim(): void
    {
        $this->station();
        $database = tempnam(sys_get_temp_dir(), 'coverage-race-');
        $barrier = $database.'.go';
        $pdo = new \PDO('sqlite:'.$database);
        // Use the actual migrated areas schema and indexes on a shared file database.
        foreach (DB::select("SELECT sql FROM sqlite_master WHERE tbl_name = 'areas' AND sql IS NOT NULL ORDER BY type DESC") as $definition) {
            $pdo->exec($definition->sql);
        }
        $script = <<<'PHP'
        $pdo = new PDO('sqlite:'.$argv[1]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $deadline = microtime(true) + 10;
        while (!file_exists($argv[2])) {
            if (microtime(true) > $deadline) exit(3);
            usleep(1000);
        }
        try {
            $pdo->exec("INSERT INTO areas (logistics_provider_id, name, municipality, barangay_code, is_active) VALUES (1, 'Race', 'City', '0305401001', 1)");
            exit(0);
        } catch (PDOException $e) {
            exit(str_contains($e->getMessage(), 'UNIQUE constraint failed') ? 2 : 3);
        }
        PHP;
        $processes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $pipes = [];
                $processes[] = proc_open([PHP_BINARY, '-r', $script, $database, $barrier], [], $pipes);
            }
            touch($barrier);
            $results = array_map(fn ($process) => proc_close($process), $processes);
            sort($results);
            $this->assertSame([0, 2], $results);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM areas')->fetchColumn());
        } finally {
            $pdo = null;
            @unlink($barrier);
            @unlink($database);
        }
    }

    public function test_populated_legacy_schema_upgrades_without_inventing_barangays_and_rolls_back(): void
    {
        $station = $this->station();
        $migration = require database_path('migrations/2026_10_21_083944_add_barangay_coverage_to_areas_table.php');
        $migration->down();
        $id = DB::table('areas')->insertGetId([
            'logistics_provider_id' => $station->logistics_provider_id, 'station_hub_id' => $station->id,
            'name' => 'Legacy retained', 'municipality' => 'San Fernando', 'city_municipality_code' => '030540100',
            'is_active' => true,
        ]);
        $migration->up();
        $this->assertDatabaseHas('areas', ['id' => $id, 'name' => 'Legacy retained', 'barangay_code' => null]);
        $migration->down();
        $this->assertDatabaseHas('areas', ['id' => $id, 'name' => 'Legacy retained']);
        $migration->up();
    }

    public function test_rollback_refuses_to_merge_multiple_barangays_in_one_municipality(): void
    {
        $station = $this->station();
        $this->cover($station);
        $this->cover($station, '0305401002');
        $migration = require database_path('migrations/2026_10_21_083944_add_barangay_coverage_to_areas_table.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse incompatible municipality uniqueness.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('multiple barangay areas', $exception->getMessage());
        }
        $this->assertSame(2, $station->areas()->whereNotNull('barangay_code')->count());
    }

    public function test_pending_station_can_have_coverage_but_cannot_activate_or_route(): void
    {
        $station = $this->station();
        $station->update(['approval_status' => 'pending']);
        $this->cover($station);
        $this->courier($station);
        $this->actingAs($station->provider, 'logistics')->patch(route('logistics.hubs.status', $station), ['is_active' => true])
            ->assertSessionHasErrors('is_active');
        $this->assertNull((new CourierAssignmentService)->reservePickup($this->parcel(OrderStatus::ReadyForPickup), $station->provider));
    }

    public function test_bulk_selection_is_atomic_when_one_barangay_conflicts(): void
    {
        $one = $this->station();
        $two = $this->station($one->provider);
        $this->cover($one, '0305401002');
        $this->actingAs($one->provider, 'logistics')->post(route('logistics.areas.store'), [
            'name' => 'Atomic selection', 'station_hub_id' => $two->id, 'city_municipality_code' => '030540100',
            'barangay_codes' => ['0305401001', '0305401002'],
        ])->assertSessionHasErrors('barangay_code');
        $this->assertSame(0, $two->areas()->count());
    }

    public function test_inactive_claim_can_coexist_but_cannot_be_reactivated_over_existing_claim(): void
    {
        $one = $this->station();
        $two = $this->station($one->provider);
        $inactive = (new LogisticsHubService)->saveCoverage($one->logistics_provider_id, $this->payload($one) + ['is_active' => false]);
        $this->cover($two);
        $this->actingAs($one->provider, 'logistics')->patch(route('logistics.areas.update', $inactive), $this->payload($one) + ['is_active' => true])
            ->assertSessionHasErrors('barangay_code');
        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_failed_pickup_reassignment_keeps_previous_rider_busy(): void
    {
        $station = $this->station();
        $this->cover($station);
        (new LogisticsHubService)->activate($station, true);
        $courier = $this->courier($station);
        $parcel = $this->parcel(OrderStatus::ReadyForPickup);
        $assignment = new CourierAssignmentService;
        $assignment->reservePickup($parcel, $station->provider, $courier->id);
        $this->assertNull($assignment->reservePickup($parcel, $station->provider, PHP_INT_MAX, true));
        $this->assertFalse($courier->fresh()->is_available);
        $this->assertSame($courier->id, $parcel->fresh()->courier_id);
    }

    public function test_coverage_page_flags_areas_without_riders_and_legacy_rows(): void
    {
        $station = $this->station();
        $staffed = $this->cover($station);
        $this->cover($station, '0305401002');
        $this->courier($station)->update(['area_id' => $staffed->id]);
        $legacy = Area::create(['logistics_provider_id' => $station->logistics_provider_id,
            'name' => 'Old municipality', 'municipality' => 'San Fernando', 'is_active' => true]);
        Area::create(['logistics_provider_id' => LogisticsProvider::factory()->create()->id,
            'name' => 'Other provider area', 'municipality' => 'Elsewhere', 'is_active' => true]);

        $response = $this->actingAs($station->provider, 'logistics')->get(route('logistics.areas.index'))
            ->assertOk()->assertSee('3 areas, 2 without riders')->assertSee('Legacy')
            ->assertSee('Convert to barangays')->assertDontSee('Other provider area');
        $this->assertSame(2, substr_count($response->getContent(), 'No rider, pickups here will fail'));

        $this->get(route('logistics.areas.index', ['convert' => $legacy->id]))
            ->assertOk()->assertSee('Convert legacy area')
            ->assertSee('action="'.route('logistics.areas.update', $legacy).'"', false)
            ->assertSee('data-single="1"', false);
    }

    public function test_legacy_conversion_uses_validated_coverage_and_preserves_the_row_id(): void
    {
        $station = $this->station();
        $legacy = Area::create(['logistics_provider_id' => $station->logistics_provider_id,
            'name' => 'Old municipality', 'municipality' => 'San Fernando', 'is_active' => true]);

        $this->actingAs($station->provider, 'logistics')
            ->patch(route('logistics.areas.update', $legacy), $this->payload($station))
            ->assertRedirect(route('logistics.areas.index', ['station' => $station->id]))->assertSessionHasNoErrors();

        $this->assertSame('0305401001', $legacy->fresh()->barangay_code);
        $this->assertNotNull($legacy->fresh()->coverage_validated_at);
        $this->assertSame(1, $station->areas()->count());
    }

    public function test_legacy_conversion_form_cannot_open_another_providers_area(): void
    {
        $station = $this->station();
        $foreign = Area::create(['logistics_provider_id' => LogisticsProvider::factory()->create()->id,
            'name' => 'Foreign legacy', 'municipality' => 'Elsewhere', 'is_active' => true]);

        $this->actingAs($station->provider, 'logistics')
            ->get(route('logistics.areas.index', ['convert' => $foreign->id]))->assertNotFound();
    }

    public function test_coverage_page_lists_claims_and_searchable_barangay_selection(): void
    {
        $station = $this->station();
        $this->cover($station);
        $this->actingAs($station->provider, 'logistics')->get(route('logistics.areas.index'))
            ->assertOk()->assertSee('Search barangays')->assertSee('First')->assertSee('0305401001');
    }
}
