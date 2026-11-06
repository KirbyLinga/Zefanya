<?php

namespace Tests\Feature\Logistics;

use App\Models\Admin\Admin;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Services\Logistics\ProviderHandoffSchemaService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PartnershipsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private LogisticsProvider $origin;

    private LogisticsProvider $destination;

    private LogisticsProvider $outsider;

    private Admin $admin;

    private ProviderHandoffSchemaService $partnerships;

    protected function setUp(): void
    {
        parent::setUp();

        $this->origin = $this->operationalProvider('Origin Logistics');
        $this->destination = $this->operationalProvider('Destination Logistics');
        $this->outsider = $this->operationalProvider('Outsider Logistics');
        $this->admin = Admin::factory()->create();
        $this->partnerships = app(ProviderHandoffSchemaService::class);
    }

    public function test_origin_can_request_a_directed_partnership_from_the_logistics_page(): void
    {
        $this->actingAs($this->origin, 'logistics')
            ->post(route('logistics.partnerships.store'), [
                'destination_provider_id' => $this->destination->id,
            ])
            ->assertRedirect(route('logistics.partnerships.index'))
            ->assertSessionHas('success');

        $link = LogisticsProviderLink::query()->sole();

        $this->assertSame($this->origin->id, (int) $link->origin_provider_id);
        $this->assertSame($this->destination->id, (int) $link->destination_provider_id);
        $this->assertSame(LogisticsProviderLink::PENDING, $link->status);
        $this->assertSame($this->origin->id, (int) $link->origin_consented_by);
        $this->assertNotNull($link->origin_consented_at);
        $this->assertNull($link->destination_consented_at);
        $this->assertNull($link->approved_at);
    }

    public function test_destination_can_accept_a_pending_request_without_activating_it(): void
    {
        $link = $this->partnerships->requestLink($this->origin, $this->destination);

        $this->actingAs($this->destination, 'logistics')
            ->post(route('logistics.partnerships.accept', $link))
            ->assertRedirect()
            ->assertSessionHas('success');

        $link->refresh();
        $this->assertSame(LogisticsProviderLink::PENDING, $link->status);
        $this->assertSame($this->destination->id, (int) $link->destination_consented_by);
        $this->assertNotNull($link->destination_consented_at);
        $this->assertNull($link->approved_at);
    }

    public function test_destination_can_reject_with_a_reason_and_consent_is_cleared(): void
    {
        $link = $this->partnerships->requestLink($this->origin, $this->destination);

        $this->actingAs($this->destination, 'logistics')
            ->post(route('logistics.partnerships.reject', $link), [
                'rejection_reason' => 'No receiving capacity for this province this quarter.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $link->refresh();
        $this->assertSame(LogisticsProviderLink::REJECTED, $link->status);
        $this->assertSame($this->destination->id, (int) $link->rejected_by);
        $this->assertNotNull($link->rejected_at);
        $this->assertSame('No receiving capacity for this province this quarter.', $link->rejection_reason);
        $this->assertNull($link->destination_consented_by);
        $this->assertNull($link->destination_consented_at);
    }

    public function test_origin_and_unrelated_providers_cannot_accept_or_reject_a_request(): void
    {
        $link = $this->partnerships->requestLink($this->origin, $this->destination);
        $reason = 'This provider is not the destination.';

        $this->actingAs($this->origin, 'logistics')
            ->post(route('logistics.partnerships.accept', $link))
            ->assertForbidden();

        $this->actingAs($this->origin, 'logistics')
            ->post(route('logistics.partnerships.reject', $link), ['rejection_reason' => $reason])
            ->assertForbidden();

        $this->actingAs($this->outsider, 'logistics')
            ->post(route('logistics.partnerships.accept', $link))
            ->assertForbidden();

        $this->actingAs($this->outsider, 'logistics')
            ->post(route('logistics.partnerships.reject', $link), ['rejection_reason' => $reason])
            ->assertForbidden();

        $link->refresh();
        $this->assertSame(LogisticsProviderLink::PENDING, $link->status);
        $this->assertNull($link->destination_consented_at);
        $this->assertNull($link->rejected_at);
    }

    public function test_providers_only_see_links_they_participate_in(): void
    {
        $this->partnerships->requestLink($this->origin, $this->destination);

        $this->actingAs($this->outsider, 'logistics')
            ->get(route('logistics.partnerships.index'))
            ->assertOk()
            ->assertViewHas('links', fn ($links) => $links->total() === 0);

        $this->actingAs($this->destination, 'logistics')
            ->get(route('logistics.partnerships.index'))
            ->assertOk()
            ->assertViewHas('links', fn ($links) => $links->total() === 1);
    }

    public function test_invalid_links_are_rejected_without_creating_rows(): void
    {
        $pending = LogisticsProvider::factory()->pending()->create();
        $uncovered = LogisticsProvider::factory()->create(['business_name' => 'No Coverage Logistics']);

        $this->actingAs($this->origin, 'logistics')
            ->post(route('logistics.partnerships.store'), ['destination_provider_id' => $this->origin->id])
            ->assertSessionHasErrors('destination_provider_id');

        $this->actingAs($this->origin, 'logistics')
            ->post(route('logistics.partnerships.store'), ['destination_provider_id' => $pending->id])
            ->assertSessionHasErrors('provider');

        $this->actingAs($this->origin, 'logistics')
            ->post(route('logistics.partnerships.store'), ['destination_provider_id' => $uncovered->id])
            ->assertSessionHasErrors('destination_provider_id');

        $this->assertSame(0, LogisticsProviderLink::query()->count());
    }

    public function test_acceptance_revalidates_destination_operational_coverage(): void
    {
        $link = $this->partnerships->requestLink($this->origin, $this->destination);

        Area::query()->where('logistics_provider_id', $this->destination->id)->firstOrFail()
            ->forceFill(['is_active' => false])->save();

        $this->actingAs($this->destination, 'logistics')
            ->post(route('logistics.partnerships.accept', $link))
            ->assertSessionHasErrors('destination_provider_id');

        $link->refresh();
        $this->assertSame(LogisticsProviderLink::PENDING, $link->status);
        $this->assertNull($link->destination_consented_at);
    }

    public function test_only_operational_destinations_are_listed_for_new_requests(): void
    {
        LogisticsProvider::factory()->create(['business_name' => 'Approved Without Coverage']);

        $this->actingAs($this->origin, 'logistics')
            ->get(route('logistics.partnerships.index'))
            ->assertOk()
            ->assertSee($this->destination->business_name)
            ->assertDontSee('Approved Without Coverage');
    }

    public function test_page_displays_pending_active_rejected_and_revoked_links(): void
    {
        $pendingDestination = $this->operationalProvider('Pending Destination');
        $activeDestination = $this->operationalProvider('Active Destination');
        $rejectedDestination = $this->operationalProvider('Rejected Destination');
        $revokedDestination = $this->operationalProvider('Revoked Destination');

        $this->partnerships->requestLink($this->origin, $pendingDestination);

        $active = $this->partnerships->requestLink($this->origin, $activeDestination);
        $this->partnerships->accept($active, $activeDestination);
        $this->partnerships->approve($active, $this->admin);

        $rejected = $this->partnerships->requestLink($this->origin, $rejectedDestination);
        $this->partnerships->reject($rejected, $rejectedDestination, 'Not serviceable.');

        $revoked = $this->partnerships->requestLink($this->origin, $revokedDestination);
        $this->partnerships->accept($revoked, $revokedDestination);
        $this->partnerships->approve($revoked, $this->admin);
        $this->partnerships->revoke($revoked, $this->admin, 'Route discontinued.');

        $this->actingAs($this->origin, 'logistics')
            ->get(route('logistics.partnerships.index'))
            ->assertOk()
            ->assertViewHas('links', fn ($links) => $links->total() === 4)
            ->assertSee('Pending Destination')
            ->assertSee('Active Destination')
            ->assertSee('Rejected Destination')
            ->assertSee('Revoked Destination')
            ->assertSee('Pending')
            ->assertSee('Active')
            ->assertSee('Rejected')
            ->assertSee('Revoked');
    }

    public function test_rejection_requires_a_reason(): void
    {
        $link = $this->partnerships->requestLink($this->origin, $this->destination);

        $this->actingAs($this->destination, 'logistics')
            ->post(route('logistics.partnerships.reject', $link), ['rejection_reason' => ''])
            ->assertSessionHasErrors('rejection_reason');

        $this->assertSame(LogisticsProviderLink::PENDING, $link->fresh()->status);
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
