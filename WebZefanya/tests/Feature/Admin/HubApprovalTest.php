<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsHubLocationChange;
use App\Models\Logistics\LogisticsProvider;
use App\Notifications\Logistics\LogisticsHubDecisionNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HubApprovalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Admin $admin;

    private LogisticsProvider $provider;

    private LogisticsHub $province;

    private LogisticsHub $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create();
        $this->provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $this->seedPsgc();

        $this->province = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'name' => 'Pampanga Province Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'municipality' => 'San Fernando',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);

        $this->station = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->provider->id,
            'parent_hub_id' => $this->province->id,
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
            'street' => '456 Mabini Avenue',
            'approval_status' => LogisticsHub::APPROVAL_PENDING,
            'is_active' => false,
        ]);
    }

    private function seedPsgc(): void
    {
        Cache::put('psgc:regions', [
            ['code' => '030000000', 'name' => 'Region III'],
        ], 3600);
        Cache::put('psgc:provinces:030000000', [
            ['code' => '030540000', 'name' => 'Pampanga'],
        ], 3600);
        Cache::put('psgc:cities:030540000:030000000', [
            ['code' => '030540100', 'name' => 'San Fernando'],
            ['code' => '030540200', 'name' => 'Angeles'],
        ], 3600);
        Cache::put('psgc:barangays:030540100', [
            ['code' => '0305401001', 'name' => 'Barangay 1'],
        ], 3600);
        Cache::put('psgc:barangays:030540200', [
            ['code' => '0305402001', 'name' => 'Barangay 2'],
        ], 3600);
    }

    private function submitProvinceLocationChange(): LogisticsHubLocationChange
    {
        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.update', $this->province), [
                'edit_hub_id' => $this->province->id,
                'name' => $this->province->name,
                'region_code' => '030000000',
                'province_code' => '030540000',
                'city_municipality_code' => '030540200',
                'barangay_code' => '0305402001',
                'street' => 'New Street',
            ])->assertRedirect()->assertSessionHasNoErrors();

        return $this->province->locationChanges()
            ->where('status', 'pending')
            ->firstOrFail();
    }

    public function test_admin_hub_queue_lists_pending_station_applications(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.hubs.index'))
            ->assertOk()
            ->assertSee('San Fernando Station')
            ->assertSee('Station Hub')
            ->assertSee('Pending Review')
            ->assertSee('Pampanga Province Hub');
    }

    public function test_admin_can_approve_station_without_activating_it(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.approve', $this->station))
            ->assertRedirect()
            ->assertSessionHas('success');

        $station = $this->station->fresh();

        $this->assertSame(LogisticsHub::APPROVAL_APPROVED, $station->approval_status);
        $this->assertFalse($station->is_active);
        $this->assertNotNull($station->approved_at);
        $this->assertSame($this->admin->id, (int) $station->approved_by);

        Notification::assertSentTo(
            $this->provider,
            LogisticsHubDecisionNotification::class,
            fn (LogisticsHubDecisionNotification $notification) => $notification->decision === 'approved'
                && $notification->hub->is($station)
        );
    }

    public function test_approved_station_requires_coverage_before_activation(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.approve', $this->station))
            ->assertSessionHas('success');

        $this->actingAs($this->provider, 'logistics')->post(route('logistics.areas.store'), [
            'name' => 'Station coverage', 'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001', 'station_hub_id' => $this->station->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.status', $this->station), ['is_active' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($this->station->fresh()->is_active);

        $this->assertDatabaseHas('areas', [
            'logistics_provider_id' => $this->provider->id,
            'station_hub_id' => $this->station->id,
            'municipality' => 'San Fernando',
        ]);
    }

    public function test_admin_can_reject_station_with_reason_and_notifies_provider(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.reject', $this->station), [
                'rejection_reason' => 'The warehouse address could not be verified.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $station = $this->station->fresh();

        $this->assertSame(LogisticsHub::APPROVAL_REJECTED, $station->approval_status);
        $this->assertFalse($station->is_active);
        $this->assertSame('The warehouse address could not be verified.', $station->rejection_reason);
        $this->assertNotNull($station->rejected_at);
        $this->assertSame($this->admin->id, (int) $station->rejected_by);

        Notification::assertSentTo(
            $this->provider,
            LogisticsHubDecisionNotification::class,
            fn (LogisticsHubDecisionNotification $notification) => $notification->decision === 'rejected'
                && $notification->reason === 'The warehouse address could not be verified.'
        );
    }

    public function test_rejection_requires_a_reason(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.reject', $this->station), [])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame(LogisticsHub::APPROVAL_PENDING, $this->station->fresh()->approval_status);
    }

    public function test_station_cannot_be_approved_twice(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.approve', $this->station))
            ->assertSessionHas('success');

        $approvedAt = $this->station->fresh()->approved_at;

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.approve', $this->station))
            ->assertSessionHas('error');

        $this->assertSame(LogisticsHub::APPROVAL_APPROVED, $this->station->fresh()->approval_status);
        $this->assertEquals($approvedAt, $this->station->fresh()->approved_at);
    }

    public function test_station_cannot_be_approved_when_parent_province_is_inactive(): void
    {
        Notification::fake();

        $this->province->update(['is_active' => false]);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.approve', $this->station))
            ->assertSessionHas('error');

        $this->assertSame(LogisticsHub::APPROVAL_PENDING, $this->station->fresh()->approval_status);
        Notification::assertNothingSent();
    }

    public function test_admin_can_approve_location_change_without_changing_hub_status(): void
    {
        $change = $this->submitProvinceLocationChange();

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.hubs.index'))
            ->assertOk()
            ->assertSee('Pending Location Changes')
            ->assertSee('New Street');

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.location-changes.approve', $change))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->province->refresh();
        $this->assertSame('030540200', $this->province->city_municipality_code);
        $this->assertSame('Angeles', $this->province->municipality);
        $this->assertSame('New Street', $this->province->street);
        $this->assertSame(LogisticsHub::APPROVAL_APPROVED, $this->province->approval_status);
        $this->assertTrue($this->province->is_active);

        $change->refresh();
        $this->assertSame('approved', $change->status);
        $this->assertSame($this->admin->id, (int) $change->reviewed_by);
        $this->assertNotNull($change->reviewed_at);
    }

    public function test_admin_can_reject_location_change_and_current_location_remains(): void
    {
        $change = $this->submitProvinceLocationChange();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.location-changes.reject', $change), [
                'rejection_reason' => 'The proposed address could not be verified.',
            ])->assertRedirect()->assertSessionHas('success');

        $this->province->refresh();
        $this->assertNull($this->province->city_municipality_code);
        $this->assertNull($this->province->street);
        $this->assertSame(LogisticsHub::APPROVAL_APPROVED, $this->province->approval_status);
        $this->assertTrue($this->province->is_active);

        $change->refresh();
        $this->assertSame('rejected', $change->status);
        $this->assertSame('The proposed address could not be verified.', $change->rejection_reason);
        $this->assertSame($this->admin->id, (int) $change->reviewed_by);
    }

    public function test_location_change_cannot_be_approved_twice(): void
    {
        $change = $this->submitProvinceLocationChange();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.location-changes.approve', $change))
            ->assertSessionHas('success');

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.location-changes.approve', $change))
            ->assertSessionHas('error');

        $this->assertSame('approved', $change->fresh()->status);
    }

    public function test_location_change_rejection_requires_a_reason(): void
    {
        $change = $this->submitProvinceLocationChange();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.location-changes.reject', $change), [])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame('pending', $change->fresh()->status);
    }

    public function test_station_location_change_approval_keeps_station_operational(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.approve', $this->station))
            ->assertSessionHas('success');

        $this->actingAs($this->provider, 'logistics')->post(route('logistics.areas.store'), [
            'name' => 'Station coverage', 'city_municipality_code' => '030540100',
            'barangay_code' => '0305401001', 'station_hub_id' => $this->station->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.status', $this->station), ['is_active' => 1])
            ->assertSessionHas('success');

        $this->actingAs($this->provider, 'logistics')
            ->patch(route('logistics.hubs.update', $this->station), [
                'edit_hub_id' => $this->station->id,
                'name' => $this->station->name,
                'city_municipality_code' => '030540200',
                'barangay_code' => '0305402001',
                'street' => 'New Station Street',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $change = $this->station->locationChanges()->where('status', 'pending')->firstOrFail();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.hubs.location-changes.approve', $change))
            ->assertSessionHas('success');

        $station = $this->station->fresh();
        $this->assertSame('030540200', $station->city_municipality_code);
        $this->assertSame('Angeles', $station->municipality);
        $this->assertSame('New Station Street', $station->street);
        $this->assertSame(LogisticsHub::APPROVAL_APPROVED, $station->approval_status);
        $this->assertTrue($station->is_active);
        $this->assertSame('approved', $change->fresh()->status);
    }

    public function test_support_role_cannot_manage_hub_approvals(): void
    {
        $support = Admin::factory()->create(['role' => 'support']);

        $this->actingAs($support, 'admin')
            ->get(route('admin.hubs.index'))
            ->assertForbidden();

        $this->actingAs($support, 'admin')
            ->post(route('admin.hubs.approve', $this->station))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_from_hub_approvals(): void
    {
        $this->get(route('admin.hubs.index'))
            ->assertRedirect(route('admin.login'));
    }
}
