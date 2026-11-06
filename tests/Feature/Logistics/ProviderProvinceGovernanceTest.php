<?php

namespace Tests\Feature\Logistics;

use App\Models\Admin\Admin;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Services\Logistics\ProviderProvinceService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProviderProvinceGovernanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create();
        $this->seedPsgc();
    }

    private function seedPsgc(): void
    {
        Cache::put('psgc:regions', [
            ['code' => '030000000', 'name' => 'Region III'],
            ['code' => '040000000', 'name' => 'Region IV-A'],
        ], 3600);
        Cache::put('psgc:provinces:030000000', [
            ['code' => '030540000', 'name' => 'Pampanga'],
            ['code' => '030140000', 'name' => 'Bulacan'],
        ], 3600);
        Cache::put('psgc:provinces:040000000', [
            ['code' => '040100000', 'name' => 'Batangas'],
        ], 3600);
        Cache::put('psgc:provinces:all', [
            ['code' => '030540000', 'name' => 'Pampanga'],
            ['code' => '030140000', 'name' => 'Bulacan'],
            ['code' => '040100000', 'name' => 'Batangas'],
        ], 3600);
        Cache::put('psgc:cities:030540000:030000000', [
            ['code' => '030540100', 'name' => 'San Fernando'],
            ['code' => '030540200', 'name' => 'Angeles'],
        ], 3600);
        Cache::put('psgc:barangays:030540100', [
            ['code' => '0305401001', 'name' => 'Barangay 1'],
            ['code' => '0305401002', 'name' => 'Barangay 2'],
        ], 3600);
        Cache::put('psgc:barangays:030540200', [
            ['code' => '0305402001', 'name' => 'Barangay A'],
        ], 3600);
    }

    private function makeProvinceHub(LogisticsProvider $provider, array $overrides = []): LogisticsHub
    {
        return LogisticsHub::query()->create(array_merge([
            'logistics_provider_id' => $provider->id,
            'name' => 'Pampanga Province Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'city_municipality_code' => '030540100',
            'municipality' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'Barangay 1',
            'street' => '1 Hub Road',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
            'active_province_slot' => 1,
        ], $overrides));
    }

    private function makeStation(LogisticsProvider $provider, LogisticsHub $province, array $overrides = []): LogisticsHub
    {
        return LogisticsHub::query()->create(array_merge([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => 'San Fernando Station',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'city_municipality_code' => '030540100',
            'municipality' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'Barangay 1',
            'street' => '2 Station Ave',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ], $overrides));
    }

    public function test_admin_approval_verifies_and_locks_provider_province(): void
    {
        Notification::fake();

        $provider = LogisticsProvider::factory()->pending()->create([
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'province_verified_at' => null,
            'province_verified_by' => null,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.registrations.logistics.approve', $provider))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $provider->refresh();

        $this->assertSame('approved', $provider->status->value);
        $this->assertNotNull($provider->province_verified_at);
        $this->assertSame($this->admin->id, $provider->province_verified_by);
        $this->assertTrue($provider->hasVerifiedProvince());
    }

    public function test_admin_cannot_approve_logistics_without_province(): void
    {
        Notification::fake();

        $provider = LogisticsProvider::factory()->pending()->create([
            'province_code' => null,
            'province_name' => null,
            'province_verified_at' => null,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.registrations.logistics.approve', $provider))
            ->assertRedirect()
            ->assertSessionHasErrors('province_code');

        $this->assertSame('pending', $provider->fresh()->status->value);
    }

    public function test_logistics_cannot_change_locked_province_via_model_update(): void
    {
        $provider = LogisticsProvider::factory()->create([
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'province_verified_at' => now(),
        ]);

        $this->expectException(HttpException::class);

        $provider->update([
            'province_code' => '040100000',
            'province_name' => 'Batangas',
        ]);
    }

    public function test_logistics_hub_update_rejects_forged_province_with_403(): void
    {
        $provider = LogisticsProvider::factory()->create();
        $hub = $this->makeProvinceHub($provider);

        $this->actingAs($provider, 'logistics')
            ->patch(route('logistics.hubs.update', $hub), [
                'edit_hub_id' => $hub->id,
                'name' => $hub->name,
                'region_code' => '040000000',
                'province_code' => '040100000',
                'province_name' => 'Batangas',
                'city_municipality_code' => '030540100',
                'barangay_code' => '0305401001',
                'street' => 'Still San Fernando',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_provision_exactly_one_live_province_hub(): void
    {
        $provider = LogisticsProvider::factory()->create();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.province.provision'), [
                'logistics_provider_id' => $provider->id,
                'name' => 'Admin Provisioned Hub',
                'region_code' => '030000000',
                'region_name' => 'Region III',
                'city_municipality_code' => '030540100',
                'barangay_code' => '0305401001',
                'street' => 'Admin Street',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $hub = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->first();

        $this->assertNotNull($hub);
        $this->assertSame(1, $hub->active_province_slot);
        $this->assertNull($hub->archived_at);
        $this->assertTrue($hub->isApproved());
        $this->assertTrue($hub->is_active);
        $this->assertSame('030540000', $hub->province_code);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.province.provision'), [
                'logistics_provider_id' => $provider->id,
                'region_code' => '030000000',
                'city_municipality_code' => '030540200',
                'barangay_code' => '0305402001',
                'street' => 'Second attempt',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('hub');

        $this->assertSame(1, LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->whereNull('archived_at')
            ->count());
    }

    public function test_competing_providers_may_share_the_same_province(): void
    {
        $a = LogisticsProvider::factory()->create(['business_name' => 'Alpha Cargo']);
        $b = LogisticsProvider::factory()->create(['business_name' => 'Beta Cargo']);

        app(ProviderProvinceService::class)->provisionProvinceHub($a, $this->admin, [
            'region_code' => '030000000',
            'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001',
            'street' => 'Alpha St',
        ]);
        app(ProviderProvinceService::class)->provisionProvinceHub($b, $this->admin, [
            'region_code' => '030000000',
            'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001',
            'street' => 'Beta St',
        ]);

        $this->assertSame(2, LogisticsHub::query()
            ->where('hub_type', LogisticsHub::PROVINCE)
            ->where('province_code', '030540000')
            ->where('active_province_slot', 1)
            ->count());
    }

    public function test_station_must_use_live_province_hub_inside_verified_province(): void
    {
        $provider = LogisticsProvider::factory()->create();
        $live = $this->makeProvinceHub($provider);

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.hubs.stations.store', $live), [
                'name' => 'Angeles Station',
                'city_municipality_code' => '030540200',
                'barangay_code' => '0305402001',
                'street' => 'Station Road',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('logistics_hubs', [
            'logistics_provider_id' => $provider->id,
            'hub_type' => LogisticsHub::STATION,
            'name' => 'Angeles Station',
            'province_code' => '030540000',
            'parent_hub_id' => $live->id,
        ]);
    }

    public function test_coverage_rejects_barangay_outside_station_province_and_keeps_provider_scoped_uniqueness(): void
    {
        $provider = LogisticsProvider::factory()->create();
        $other = LogisticsProvider::factory()->create(['business_name' => 'Other Co']);
        $province = $this->makeProvinceHub($provider);
        $station = $this->makeStation($provider, $province);
        $otherProvince = $this->makeProvinceHub($other, ['name' => 'Other Province Hub']);
        $otherStation = $this->makeStation($other, $otherProvince, ['name' => 'Other Station']);

        // Valid coverage for provider A.
        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.areas.store'), [
                'name' => 'Brgy 1 Coverage',
                'station_hub_id' => $station->id,
                'city_municipality_code' => '030540100',
                'barangay_code' => '0305401001',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Competing provider may claim the same barangay.
        $this->actingAs($other, 'logistics')
            ->post(route('logistics.areas.store'), [
                'name' => 'Other Brgy 1',
                'station_hub_id' => $otherStation->id,
                'city_municipality_code' => '030540100',
                'barangay_code' => '0305401001',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Area::query()->where('barangay_code', '0305401001')->where('is_active', true)->count());

        // Same provider cannot double-claim.
        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.areas.store'), [
                'name' => 'Duplicate',
                'station_hub_id' => $station->id,
                'city_municipality_code' => '030540100',
                'barangay_code' => '0305401001',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('barangay_code');

        // Cross-provider station id is rejected.
        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.areas.store'), [
                'name' => 'Forged',
                'station_hub_id' => $otherStation->id,
                'city_municipality_code' => '030540100',
                'barangay_code' => '0305401002',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('station_hub_id');
    }

    public function test_admin_province_change_blocked_when_stations_exist(): void
    {
        $provider = LogisticsProvider::factory()->create();
        $province = $this->makeProvinceHub($provider);
        $this->makeStation($provider, $province);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.users.logistics.province', $provider), [
                'province_code' => '030140000',
                'province_name' => 'Bulacan',
                'region_code' => '030000000',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('province_code');

        $this->assertSame('030540000', $provider->fresh()->province_code);
    }

    public function test_admin_can_change_province_when_blockers_cleared_and_archives_live_hub(): void
    {
        $provider = LogisticsProvider::factory()->create();
        $hub = $this->makeProvinceHub($provider);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.users.logistics.province', $provider), [
                'province_code' => '030140000',
                'province_name' => 'Bulacan',
                'region_code' => '030000000',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $provider->refresh();
        $hub->refresh();

        $this->assertSame('030140000', $provider->province_code);
        $this->assertSame('Bulacan', $provider->province_name);
        $this->assertNotNull($hub->archived_at);
        $this->assertNull($hub->active_province_slot);
        $this->assertFalse($hub->is_active);
        $this->assertNull(app(ProviderProvinceService::class)->activeProvinceHub($provider));
    }

    public function test_admin_province_change_blocked_by_riders_on_stations(): void
    {
        $provider = LogisticsProvider::factory()->create();
        $province = $this->makeProvinceHub($provider);
        $station = $this->makeStation($provider, $province);

        Rider::factory()->create([
            'logistics_provider_id' => $provider->id,
            'station_hub_id' => $station->id,
        ]);

        $blockers = app(ProviderProvinceService::class)->provinceChangeBlockers($provider);

        $this->assertNotEmpty($blockers);
        $this->assertTrue(collect($blockers)->contains(fn (string $b) => str_contains($b, 'rider')));
    }

    public function test_logistics_hubs_ui_shows_read_only_province(): void
    {
        $provider = LogisticsProvider::factory()->create([
            'province_name' => 'Pampanga',
        ]);
        $this->makeProvinceHub($provider);

        $this->actingAs($provider, 'logistics')
            ->get(route('logistics.hubs.index'))
            ->assertOk()
            ->assertSee('Verified service province', false)
            ->assertSee('Pampanga')
            ->assertSee('Contact Admin to change the service province', false);
    }

    public function test_admin_hubs_ui_lists_providers_needing_province_hub(): void
    {
        LogisticsProvider::factory()->create([
            'business_name' => 'Needs Hub Logistics',
        ]);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.hubs.index'))
            ->assertOk()
            ->assertSee('Provision Province Hub')
            ->assertSee('Needs Hub Logistics');
    }

    public function test_unapproved_provider_cannot_receive_province_hub(): void
    {
        $provider = LogisticsProvider::factory()->pending()->create([
            'province_verified_at' => null,
        ]);

        $this->expectException(ValidationException::class);

        app(ProviderProvinceService::class)->provisionProvinceHub($provider, $this->admin, [
            'region_code' => '030000000',
            'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001',
            'street' => 'Nope',
        ]);
    }
}
