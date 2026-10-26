<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Courier;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\CourierAssignmentService;
use App\Services\RiderRegistrationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class AreaManagementTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    private Courier $courier;

    private Area $first;

    private Area $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $this->courier = $this->assignableCourier($this->provider, [], 'San Fernando', '030540100');
        $this->first = $this->courier->area;
        $this->second = Area::create([
            'logistics_provider_id' => $this->provider->id,
            'station_hub_id' => $this->first->station_hub_id,
            'name' => 'Second', 'municipality' => 'San Fernando',
            'city_municipality_code' => '030540100', 'barangay_code' => '0305401002',
            'barangay_name' => 'Second', 'coverage_validated_at' => now(), 'is_active' => true,
        ]);
        $this->actingAs($this->provider, 'logistics');
    }

    public function test_rider_can_link_multiple_barangays_without_replacing_existing_links_or_duplicates(): void
    {
        $payload = ['courier_id' => $this->courier->id, 'area_ids' => [$this->second->id]];
        $this->post(route('logistics.areas.couriers.linkBarangays'), $payload)->assertSessionHasNoErrors();
        $this->post(route('logistics.areas.couriers.linkBarangays'), $payload)->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], $this->courier->areas()->pluck('areas.id')->all());
        $this->assertSame(1, $this->second->couriers()->count());
    }

    public function test_foreign_area_in_a_batch_is_rejected_without_adding_any_links(): void
    {
        $other = LogisticsProvider::factory()->create();
        $foreign = $this->assignableCourier($other)->area;
        $this->post(route('logistics.areas.couriers.linkBarangays'), [
            'courier_id' => $this->courier->id, 'area_ids' => [$this->second->id, $foreign->id],
        ])->assertSessionHasErrors('area_ids');
        $this->assertSame([$this->first->id], $this->courier->areas()->pluck('areas.id')->all());
    }

    public function test_inactive_coverage_cannot_be_linked(): void
    {
        $this->second->update(['is_active' => false]);
        $this->post(route('logistics.areas.couriers.linkBarangays'), [
            'courier_id' => $this->courier->id, 'area_ids' => [$this->second->id],
        ])->assertSessionHasErrors('area_ids');
        $this->assertSame(0, $this->second->couriers()->count());
    }

    public function test_foreign_rider_cannot_be_linked(): void
    {
        $foreign = $this->assignableCourier(LogisticsProvider::factory()->create());
        $this->post(route('logistics.areas.couriers.linkBarangays'), [
            'courier_id' => $foreign->id, 'area_ids' => [$this->second->id],
        ])->assertNotFound();
    }

    public function test_rider_cannot_link_barangays_in_a_different_station(): void
    {
        $station = $this->first->station->replicate();
        $station->name = 'Other station';
        $station->save();
        $this->second->update(['station_hub_id' => $station->id]);

        $this->post(route('logistics.areas.couriers.linkBarangays'), [
            'courier_id' => $this->courier->id, 'area_ids' => [$this->second->id],
        ])->assertSessionHasErrors('courier_id');

        $this->assertSame(0, $this->second->couriers()->count());
    }

    public function test_changing_station_clears_all_previous_station_links(): void
    {
        Notification::fake();
        $station = $this->first->station->replicate();
        $station->name = 'New station';
        $station->save();
        $this->courier->areas()->attach($this->second);

        app(RiderRegistrationService::class)->changeStation($this->courier->rider, $this->provider, $station->id);

        $this->assertSame(0, $this->courier->areas()->count());
        $this->assertNull($this->courier->fresh()->area_id);
    }

    public function test_migration_preserves_existing_single_area_links(): void
    {
        $migration = require database_path('migrations/2026_10_25_141946_create_area_courier_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame([$this->first->id], $this->courier->areas()->pluck('areas.id')->all());
    }

    public function test_page_and_dispatch_use_existing_links_when_the_pivot_migration_is_pending(): void
    {
        $migration = require database_path('migrations/2026_10_25_141946_create_area_courier_table.php');
        $migration->down();
        try {
            $this->get(route('logistics.areas.index'))->assertOk()
                ->assertSee($this->courier->name)
                ->assertSee('Existing rider links are shown.')
                ->assertViewHas('areas', fn ($areas) => (int) $areas->firstWhere('id', $this->first->id)->couriers_count === 1);
            $this->assertSame([$this->first->id], $this->courier->areas()->pluck('areas.id')->all());
            $parcel = SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create([
                'carrier_provider_id' => $this->provider->id,
                'seller_id' => Seller::factory()->create(['barangay_code' => $this->first->barangay_code])->id,
            ]);
            $reserved = app(CourierAssignmentService::class)->reservePickup($parcel, $this->provider, $this->courier->id);
            $this->assertNotNull($reserved);
            $this->assertSame($this->courier->id, $reserved->id);
        } finally {
            $migration->up();
        }
    }

    public function test_pending_migration_blocks_new_links_and_allows_unlinking_the_existing_link(): void
    {
        $migration = require database_path('migrations/2026_10_25_141946_create_area_courier_table.php');
        $migration->down();
        try {
            $this->post(route('logistics.areas.couriers.linkBarangays'), [
                'courier_id' => $this->courier->id, 'area_ids' => [$this->second->id],
            ])->assertSessionHasErrors('area_ids');
            $this->assertSame($this->first->id, (int) $this->courier->fresh()->area_id);
            $this->delete(route('logistics.areas.couriers.unassign', [$this->first, $this->courier]))
                ->assertSessionHasNoErrors();
            $this->assertNull($this->courier->fresh()->area_id);
        } finally {
            $migration->up();
        }
    }

    public function test_unlink_keeps_other_barangays_and_existing_parcel_assignments(): void
    {
        $this->courier->areas()->attach($this->second);
        $parcel = SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create(['courier_id' => $this->courier->id]);

        $this->delete(route('logistics.areas.couriers.unassign', [$this->first, $this->courier]))->assertSessionHasNoErrors();

        $this->assertSame([$this->second->id], $this->courier->areas()->pluck('areas.id')->all());
        $this->assertSame($this->second->id, (int) $this->courier->fresh()->area_id);
        $this->assertSame($this->courier->id, (int) $parcel->fresh()->courier_id);
    }

    public function test_removal_still_blocks_coverage_with_linked_riders(): void
    {
        $this->courier->areas()->attach($this->second);
        $this->delete(route('logistics.areas.destroy', $this->second))->assertSessionHasErrors('coverage');
        $this->assertModelExists($this->second);
    }

    public function test_dispatch_can_use_a_second_linked_barangay(): void
    {
        $this->courier->areas()->attach($this->second);
        $parcel = SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create([
            'carrier_provider_id' => $this->provider->id,
            'seller_id' => Seller::factory()->create(['barangay_code' => $this->second->barangay_code])->id,
        ]);

        $reserved = app(CourierAssignmentService::class)->reservePickup($parcel, $this->provider, $this->courier->id);

        $this->assertNotNull($reserved);
        $this->assertSame($this->courier->id, $reserved->id);
    }

    public function test_coverage_name_is_optional_and_success_explains_the_next_step(): void
    {
        Cache::put('psgc:cities:030540000:030000000', [['code' => '030540100', 'name' => 'San Fernando']], 3600);
        Cache::put('psgc:barangays:030540100', [['code' => '0305401003', 'name' => 'Third']], 3600);

        $this->post(route('logistics.areas.store'), [
            'station_hub_id' => $this->first->station_hub_id,
            'city_municipality_code' => '030540100', 'barangay_codes' => ['0305401003'],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Barangay coverage saved. Next: link riders to these barangays.');

        $this->assertDatabaseHas('areas', ['logistics_provider_id' => $this->provider->id, 'barangay_code' => '0305401003', 'name' => 'San Fernando']);
    }

    public function test_page_shows_linked_barangays_status_workload_and_impact_confirmation(): void
    {
        $this->courier->areas()->attach($this->second);
        SellerOrder::factory()->status(OrderStatus::ReadyForPickup)->create([
            'carrier_provider_id' => $this->provider->id, 'courier_id' => $this->courier->id,
            'seller_id' => Seller::factory()->create(['barangay_code' => $this->second->barangay_code])->id,
        ]);

        $this->get(route('logistics.areas.index'))->assertOk()
            ->assertSee('1/1 busy')->assertSee('Approved')->assertSee('Select all available')
            ->assertSee('data-confirm=', false)->assertSee('1 rider and 1 pending pickup')
            ->assertSee('Link rider to selected barangays')->assertSee('Second');
    }
}
