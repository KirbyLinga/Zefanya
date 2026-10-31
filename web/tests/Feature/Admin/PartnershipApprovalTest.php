<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Services\Logistics\ProviderHandoffSchemaService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartnershipApprovalTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Admin $admin;

    private LogisticsProvider $origin;

    private LogisticsProvider $destination;

    private ProviderHandoffSchemaService $partnerships;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create();
        $this->origin = $this->operationalProvider('Origin Logistics');
        $this->destination = $this->operationalProvider('Destination Logistics');
        $this->partnerships = app(ProviderHandoffSchemaService::class);
    }

    public function test_admin_can_approve_an_accepted_link_with_priority_and_activate_it(): void
    {
        $link = $this->acceptedLink();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.partnerships.approve', $link), ['priority' => 25])
            ->assertRedirect()
            ->assertSessionHas('success');

        $link->refresh();
        $this->assertSame(LogisticsProviderLink::ACTIVE, $link->status);
        $this->assertSame(25, $link->priority);
        $this->assertSame($this->admin->id, (int) $link->approved_by);
        $this->assertNotNull($link->approved_at);
        $this->assertSame(1, (int) DB::table('logistics_provider_links')->where('id', $link->id)->value('active_slot'));
    }

    public function test_admin_can_change_the_priority_of_a_pending_or_active_link(): void
    {
        $pending = $this->acceptedLink();
        $this->actingAs($this->admin, 'admin')
            ->patch(route('admin.partnerships.priority', $pending), ['priority' => 7])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(7, $pending->fresh()->priority);

        $active = $this->partnerships->approve($pending, $this->admin, 7);

        $this->actingAs($this->admin, 'admin')
            ->patch(route('admin.partnerships.priority', $active), ['priority' => 12])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(12, $active->fresh()->priority);
        $this->assertSame(LogisticsProviderLink::ACTIVE, $active->fresh()->status);
    }

    public function test_admin_can_revoke_an_active_link_without_deleting_its_history(): void
    {
        $link = $this->activeLink();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.partnerships.revoke', $link), [
                'revocation_reason' => 'Coverage ended in the destination province.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $link->refresh();
        $this->assertSame(LogisticsProviderLink::REVOKED, $link->status);
        $this->assertSame($this->admin->id, (int) $link->revoked_by);
        $this->assertNotNull($link->revoked_at);
        $this->assertSame('Coverage ended in the destination province.', $link->revocation_reason);
        $this->assertNotNull($link->approved_at);
        $this->assertNotNull($link->destination_consented_at);
        $this->assertNull(DB::table('logistics_provider_links')->where('id', $link->id)->value('active_slot'));
        $this->assertSame(1, LogisticsProviderLink::query()->count());
    }

    public function test_revocation_requires_a_reason_and_active_status(): void
    {
        $active = $this->activeLink();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.partnerships.revoke', $active), ['revocation_reason' => ''])
            ->assertSessionHasErrors('revocation_reason');

        $this->assertSame(LogisticsProviderLink::ACTIVE, $active->fresh()->status);

        $pending = $this->partnerships->requestLink($this->origin, $this->destination);

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.partnerships.revoke', $pending), [
                'revocation_reason' => 'Not active yet.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(LogisticsProviderLink::PENDING, $pending->fresh()->status);
    }

    public function test_approval_and_priority_inputs_are_validated(): void
    {
        $link = $this->acceptedLink();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.partnerships.approve', $link), ['priority' => -1])
            ->assertSessionHasErrors('priority');

        $this->actingAs($this->admin, 'admin')
            ->patch(route('admin.partnerships.priority', $link), ['priority' => -5])
            ->assertSessionHasErrors('priority');

        $this->assertSame(LogisticsProviderLink::PENDING, $link->fresh()->status);
        $this->assertSame(0, $link->fresh()->priority);
    }

    public function test_approval_revalidates_destination_operational_coverage(): void
    {
        $link = $this->acceptedLink();

        Area::query()->where('logistics_provider_id', $this->destination->id)->firstOrFail()
            ->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.partnerships.approve', $link), ['priority' => 5])
            ->assertSessionHasErrors('destination_provider_id');

        $link->refresh();
        $this->assertSame(LogisticsProviderLink::PENDING, $link->status);
        $this->assertNull($link->approved_at);
    }

    public function test_only_pending_links_can_be_approved(): void
    {
        $link = $this->activeLink();

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.partnerships.approve', $link), ['priority' => 3])
            ->assertSessionHasErrors('status');

        $this->assertSame(LogisticsProviderLink::ACTIVE, $link->fresh()->status);
    }

    public function test_admin_queue_lists_pending_active_rejected_and_revoked_links(): void
    {
        $pendingDestination = $this->operationalProvider('Pending Destination');
        $activeDestination = $this->operationalProvider('Active Destination');
        $rejectedDestination = $this->operationalProvider('Rejected Destination');
        $revokedDestination = $this->operationalProvider('Revoked Destination');

        $pending = $this->partnerships->requestLink($this->origin, $pendingDestination);
        $this->partnerships->accept($pending, $pendingDestination);

        $active = $this->partnerships->requestLink($this->origin, $activeDestination);
        $this->partnerships->accept($active, $activeDestination);
        $this->partnerships->approve($active, $this->admin);

        $rejected = $this->partnerships->requestLink($this->origin, $rejectedDestination);
        $this->partnerships->reject($rejected, $rejectedDestination, 'Coverage unavailable.');

        $revoked = $this->partnerships->requestLink($this->origin, $revokedDestination);
        $this->partnerships->accept($revoked, $revokedDestination);
        $this->partnerships->approve($revoked, $this->admin);
        $this->partnerships->revoke($revoked, $this->admin, 'Route retired.');

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.partnerships.index', ['status' => 'all']))
            ->assertOk()
            ->assertViewHas('links', fn ($links) => $links->total() === 4)
            ->assertSee('Pending Destination')
            ->assertSee('Active Destination')
            ->assertSee('Rejected Destination')
            ->assertSee('Revoked Destination');
    }

    public function test_admin_queue_warns_that_destination_acceptance_is_required(): void
    {
        $this->partnerships->requestLink($this->origin, $this->destination);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.partnerships.index'))
            ->assertOk()
            ->assertSee('Awaiting destination')
            ->assertSee('Destination response required');
    }

    public function test_support_admin_cannot_access_partnership_approvals(): void
    {
        $support = Admin::factory()->role(AdminRole::Support)->create();

        $this->actingAs($support, 'admin')
            ->get(route('admin.partnerships.index'))
            ->assertForbidden();
    }

    private function acceptedLink(): LogisticsProviderLink
    {
        $link = $this->partnerships->requestLink($this->origin, $this->destination);
        $this->partnerships->accept($link, $this->destination);

        return $link;
    }

    private function activeLink(): LogisticsProviderLink
    {
        $link = $this->acceptedLink();

        return $this->partnerships->approve($link, $this->admin);
    }

    private function operationalProvider(string $businessName): LogisticsProvider
    {
        $provider = LogisticsProvider::factory()->create([
            'business_name' => $businessName,
            'province_verified_at' => now(),
        ]);

        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => "{$businessName} Province Hub",
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'municipality' => 'San Fernando',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);

        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => "{$businessName} Station",
            'hub_type' => LogisticsHub::STATION,
            'region_code' => '030000000',
            'region_name' => 'Region III',
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'city_municipality_code' => '030540100',
            'municipality' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'Barangay 1',
            'street' => '1 Coverage Street',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);

        Area::query()->create([
            'logistics_provider_id' => $provider->id,
            'station_hub_id' => $station->id,
            'name' => "{$businessName} Coverage",
            'city_municipality_code' => '030540100',
            'municipality' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'Barangay 1',
            'coverage_validated_at' => now(),
            'is_active' => true,
        ]);

        return $provider;
    }
}
