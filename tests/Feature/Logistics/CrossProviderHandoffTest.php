<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelHandoffException;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\ParcelHandoffService;
use App\Services\ProviderHandoffSchemaService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class CrossProviderHandoffTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    private Admin $admin;

    private LogisticsProvider $origin;

    private LogisticsProvider $destination;

    private LogisticsProvider $otherDestination;

    private Seller $seller;

    private Buyer $buyer;

    private SellerOrder $parcel;

    private ParcelHandoffService $handoffs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create();
        $this->origin = $this->operationalHandoffProvider(
            'Origin Logistics',
            '030540000',
            'Pampanga',
            '030540100',
            'San Fernando',
            '0305401001',
            'Santo Rosario',
        )['provider'];

        $this->destination = $this->operationalHandoffProvider(
            'Destination Logistics',
            '137404000',
            'Metro Manila',
            '137404000',
            'Quezon City',
            '1374040001',
            'Diliman',
            '130000000',
            'National Capital Region',
        )['provider'];

        $this->otherDestination = $this->operationalHandoffProvider(
            'Other Destination Logistics',
            '137404000',
            'Metro Manila',
            '137404000',
            'Quezon City',
            '1374040001',
            'Diliman',
            '130000000',
            'National Capital Region',
        )['provider'];

        $this->seller = $this->sellerIn(
            '030540000',
            'Pampanga',
            '030540100',
            'San Fernando',
            '0305401001',
            'Santo Rosario',
        );
        $this->buyer = $this->buyerIn(
            '137404000',
            'Metro Manila',
            '137404000',
            'Quezon City',
            '1374040001',
            'Diliman',
        );
        $this->parcel = $this->handoffParcel($this->seller, $this->buyer, $this->origin, 'TRK-PHASE53-0001');
        $this->handoffs = app(ParcelHandoffService::class);
    }

    public function test_origin_requests_handoff_for_its_own_parcel_and_priority_selects_destination(): void
    {
        $lowPriority = $this->activeHandoffLink($this->origin, $this->otherDestination, $this->admin, 20);
        $highPriority = $this->activeHandoffLink($this->origin, $this->destination, $this->admin, 5);

        $handoff = $this->handoffs->request($this->parcel, $this->origin);

        $this->assertSame(ParcelHandoffStatus::Requested, $handoff->status);
        $this->assertSame($this->destination->id, (int) $handoff->destination_provider_id);
        $this->assertSame($highPriority->id, (int) $handoff->logistics_provider_link_id);
        $this->assertNotSame($lowPriority->id, (int) $handoff->logistics_provider_link_id);
        $this->assertNotNull($handoff->requested_at);
        $this->assertSame('logistics', $handoff->requested_by_type);
        $this->assertSame($this->origin->id, (int) $handoff->requested_by_id);
    }

    public function test_provider_cannot_request_handoff_for_another_providers_parcel(): void
    {
        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);

        try {
            $this->handoffs->request($this->parcel, $this->destination);
            $this->fail('A provider requested a handoff for another provider\'s parcel.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(0, ParcelHandoff::query()->where('seller_order_id', $this->parcel->id)->count());
    }

    public function test_inactive_or_revoked_links_are_never_selectable(): void
    {
        $link = $this->activeHandoffLink($this->origin, $this->destination, $this->admin);

        app(ProviderHandoffSchemaService::class)->revoke(
            $link,
            $this->admin,
            'Coverage withdrawn for this test route.',
        );

        $this->assertSame(LogisticsProviderLink::REVOKED, $link->fresh()->status);
        $this->expectException(ValidationException::class);
        $this->handoffs->request($this->parcel, $this->origin);
    }

    public function test_destination_accepts_its_own_request_without_custody_transfer(): void
    {
        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $handoff = $this->handoffs->request($this->parcel, $this->origin);

        $accepted = $this->handoffs->accept($handoff, $this->destination);
        $parcel = $this->parcel->fresh();

        $this->assertSame(ParcelHandoffStatus::Accepted, $accepted->status);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertSame($this->destination->id, (int) $accepted->accepted_by_id);
        $this->assertNull($parcel->custody_provider_id);
        $this->assertSame($this->origin->id, (int) $parcel->carrier_provider_id);
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->status);
        $this->assertSame('TRK-PHASE53-0001', $parcel->tracking_number);
    }

    public function test_unrelated_provider_cannot_accept_or_reject_the_request(): void
    {
        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $handoff = $this->handoffs->request($this->parcel, $this->origin);

        try {
            $this->handoffs->accept($handoff, $this->otherDestination);
            $this->fail('An unrelated provider accepted the handoff.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        try {
            $this->handoffs->reject($handoff, $this->otherDestination, 'Not ours.');
            $this->fail('An unrelated provider rejected the handoff.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(ParcelHandoffStatus::Requested, $handoff->fresh()->status);
    }

    public function test_rejection_advances_to_the_next_priority_partner_and_keeps_custody(): void
    {
        $first = $this->activeHandoffLink($this->origin, $this->destination, $this->admin, 1);
        $second = $this->activeHandoffLink($this->origin, $this->otherDestination, $this->admin, 2);

        $handoff = $this->handoffs->request($this->parcel, $this->origin);
        $this->assertSame($first->id, (int) $handoff->logistics_provider_link_id);

        $rejected = $this->handoffs->reject($handoff, $this->destination, 'No receiving capacity.');

        $this->assertSame(ParcelHandoffStatus::Rejected, $rejected->status);
        $this->assertSame('No receiving capacity.', $rejected->rejection_reason);
        $this->assertNull($this->parcel->fresh()->custody_provider_id);

        $next = ParcelHandoff::query()
            ->where('seller_order_id', $this->parcel->id)
            ->where('status', 'REQUESTED')
            ->first();

        $this->assertNotNull($next);
        $this->assertSame($second->id, (int) $next->logistics_provider_link_id);
        $this->assertSame($this->otherDestination->id, (int) $next->destination_provider_id);
    }

    public function test_no_eligible_partner_creates_an_admin_visible_exception(): void
    {
        try {
            $this->handoffs->request($this->parcel, $this->origin);
            $this->fail('A handoff was created without any approved partner.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('handoff', $exception->errors());
        }

        $this->assertDatabaseHas('parcel_handoff_exceptions', [
            'seller_order_id' => $this->parcel->id,
            'origin_provider_id' => $this->origin->id,
            'status' => ParcelHandoffException::OPEN,
        ]);
        $this->assertSame(0, ParcelHandoff::query()->where('seller_order_id', $this->parcel->id)->count());
        $this->assertNull($this->parcel->fresh()->custody_provider_id);
    }

    public function test_duplicate_active_requests_are_idempotent_but_a_second_destination_is_blocked(): void
    {
        $link = $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $other = $this->activeHandoffLink($this->origin, $this->otherDestination, $this->admin, 50);

        $first = $this->handoffs->request($this->parcel, $this->origin, $link);
        $again = $this->handoffs->request($this->parcel, $this->origin, $link);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, ParcelHandoff::query()->where('seller_order_id', $this->parcel->id)->count());

        $this->expectException(ValidationException::class);
        $this->handoffs->request($this->parcel, $this->origin, $other);
    }

    public function test_rejection_without_another_partner_opens_an_exception_and_keeps_custody(): void
    {
        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $handoff = $this->handoffs->request($this->parcel, $this->origin);

        $rejected = $this->handoffs->reject($handoff, $this->destination, 'No capacity for this route.');

        $this->assertSame(ParcelHandoffStatus::Rejected, $rejected->status);
        $this->assertSame(1, ParcelHandoff::query()->where('seller_order_id', $this->parcel->id)->count());
        $this->assertDatabaseHas('parcel_handoff_exceptions', [
            'seller_order_id' => $this->parcel->id,
            'origin_provider_id' => $this->origin->id,
            'status' => ParcelHandoffException::OPEN,
        ]);
        $this->assertNull($this->parcel->fresh()->custody_provider_id);
    }

    public function test_restoring_a_partner_automatically_resolves_the_open_exception(): void
    {
        try {
            $this->handoffs->request($this->parcel, $this->origin);
            $this->fail('A handoff was created without any approved partner.');
        } catch (ValidationException) {
            // Expected: no eligible partner yet.
        }

        $this->assertDatabaseHas('parcel_handoff_exceptions', [
            'seller_order_id' => $this->parcel->id,
            'status' => ParcelHandoffException::OPEN,
        ]);

        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $handoff = $this->handoffs->request($this->parcel, $this->origin);

        $this->assertSame(ParcelHandoffStatus::Requested, $handoff->status);
        $this->assertDatabaseHas('parcel_handoff_exceptions', [
            'seller_order_id' => $this->parcel->id,
            'status' => ParcelHandoffException::RESOLVED,
        ]);
        $this->assertSame(0, ParcelHandoffException::query()
            ->where('seller_order_id', $this->parcel->id)
            ->where('status', ParcelHandoffException::OPEN)
            ->count());
    }

    public function test_admin_can_see_open_handoff_exceptions(): void
    {
        try {
            $this->handoffs->request($this->parcel, $this->origin);
            $this->fail('A handoff was created without any approved partner.');
        } catch (ValidationException) {
            // Expected: the parcel is held and an exception is recorded.
        }

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.partnerships.index'))
            ->assertOk()
            ->assertSee('Cross-provider handoff exceptions')
            ->assertSee('TRK-PHASE53-0001')
            ->assertSee('Origin Logistics');
    }

    public function test_http_endpoints_scope_acceptance_to_the_destination(): void
    {
        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $handoff = $this->handoffs->request($this->parcel, $this->origin);

        $this->actingAs($this->otherDestination, 'logistics')
            ->post(route('logistics.handoffs.accept', $handoff))
            ->assertForbidden();

        $this->assertSame(ParcelHandoffStatus::Requested, $handoff->fresh()->status);

        $this->actingAs($this->destination, 'logistics')
            ->post(route('logistics.handoffs.accept', $handoff))
            ->assertRedirect();

        $this->assertSame(ParcelHandoffStatus::Accepted, $handoff->fresh()->status);
    }
}
