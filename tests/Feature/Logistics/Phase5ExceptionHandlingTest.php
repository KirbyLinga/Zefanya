<?php

namespace Tests\Feature\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelIncidentType;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\DeliveryProof;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelRoute;
use App\Models\Logistics\ParcelRouteRereoute;
use App\Models\Logistics\TransportConnection;
use App\Models\Seller\Seller;
use App\Models\Shared\Incidents;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Logistics\ParcelLegService;
use App\Services\Logistics\RouteExecutionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/**
 * Phase 5: parcel exception lifecycle and authorized rerouting.
 *
 * The suite exercises the shared incidents/incident_events record, the saved
 * ParcelRoute, and the existing manifest/handoff custody evidence. Resolution
 * must never fabricate scans, custody, delivery, refund or stock records.
 */
class Phase5ExceptionHandlingTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    private ParcelIncidentService $incidents;

    private ParcelLegService $legs;

    private RouteExecutionService $routes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');

        $this->buyer = Buyer::factory()->create([
            'street' => 'Test Street',
            'municipality_name' => 'San Fernando',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'barangay_code' => '0305401001',
            'barangay_name' => 'First',
        ]);
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);

        $this->incidents = app(ParcelIncidentService::class);
        $this->legs = app(ParcelLegService::class);
        $this->routes = app(RouteExecutionService::class);
    }

    // ── Lifecycle, audit and idempotency ────────────────────────────────────

    public function test_operational_exception_moves_open_to_under_review_to_resolved_with_audit(): void
    {
        $parcel = $this->parcelInCustody();
        $provider = $this->checkoutCarrier;
        $incident = $this->incidents->reportOperational($parcel, $provider, $this->payload('missing'));

        $this->assertSame(ParcelIncidentStatus::Open, $incident->fresh()->status);
        $this->assertSame('missing', $incident->type);
        $this->assertSame(0, (int) $incident->blocked_leg_index);

        $this->actingAs($provider, 'logistics')
            ->post(route('logistics.exceptions.review', $incident), ['review_note' => 'Checking with the receiving hub.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame(ParcelIncidentStatus::UnderReview, $incident->status);
        $this->assertSame('logistics_provider', $incident->reviewed_by_type);
        $this->assertSame($provider->id, $incident->reviewed_by_id);
        $this->assertTrue($incident->events()->where('event', 'under_review')->exists());

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.incidents.resolve', $incident), [
                'resolution' => 'RESUME_ORIGINAL_ROUTE',
                'resolution_note' => 'Parcel was located at the hub.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame(ParcelIncidentStatus::Resolved, $incident->status);
        $this->assertSame('RESUME_ORIGINAL_ROUTE', $incident->resolution);
        $this->assertSame('admin', $incident->resolved_by_type);
        $this->assertSame($admin->id, $incident->resolved_by_id);
        $this->assertSame(['reported', 'under_review', 'resolved'], $incident->events()->pluck('event')->all());
        $this->assertSame('Parcel was located at the hub.', $incident->events()->where('event', 'resolved')->sole()->resolution_reason);
    }

    public function test_review_and_duplicate_resolution_are_idempotent(): void
    {
        $parcel = $this->parcelInCustody();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('wrong_hub'));

        $this->incidents->review($incident, $this->checkoutCarrier, 'First review.');
        $this->incidents->review($incident->fresh(), $this->checkoutCarrier, 'Second review.');

        $this->assertSame(1, $incident->events()->where('event', 'under_review')->count());

        $admin = Admin::factory()->create();
        $this->incidents->resolve($incident->fresh(), $admin, ['event' => 'KEEP_ON_HOLD', 'reason' => 'Waiting for the network team.']);
        $this->incidents->resolve($incident->fresh(), $admin, ['event' => 'KEEP_ON_HOLD', 'reason' => 'Waiting for the network team.']);

        $this->assertSame(1, $incident->events()->where('event', 'held')->count());
        $this->assertSame(ParcelIncidentStatus::UnderReview, $incident->fresh()->status);
        $this->assertSame(0, ParcelRouteRereoute::count());
    }

    public function test_duplicate_operational_report_returns_the_same_active_exception(): void
    {
        $parcel = $this->parcelInCustody();

        $first = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('missing'));
        $second = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('missing'));

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Incidents::query()->where('type', 'missing')->count());
        $this->assertSame(1, $first->events()->where('event', 'reported')->count());
    }

    public function test_detected_routing_blocker_is_idempotent(): void
    {
        $parcel = $this->parcelInCustody();

        $first = $this->incidents->recordRoutingBlocker($parcel, ParcelIncidentType::InactiveConnection, 'Saved connection disabled.');
        $second = $this->incidents->recordRoutingBlocker($parcel->fresh(), ParcelIncidentType::InactiveConnection, 'Saved connection disabled.');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Incidents::count());
        $this->assertSame('system', $first->reporter_type);
    }

    // ── Blocking and resolution semantics ──────────────────────────────────

    public function test_active_exception_blocks_manifest_movement_until_resumed(): void
    {
        $origin = $this->originStation();
        $middle = $this->makeStation('Middle Hub', '030540200');
        $destination = $this->makeStation('Destination Hub', '030540300');
        $this->connect($origin, $middle);
        $this->connect($middle, $destination);
        $order = $this->orderOnRoute($origin, $destination, [
            ['hub_id' => $middle->id, 'transport_mode' => TransportConnection::ROAD],
            ['hub_id' => $destination->id, 'transport_mode' => TransportConnection::ROAD],
        ]);

        $manifest = Manifest::create([
            'logistics_provider_id' => $this->checkoutCarrier->id,
            'origin_hub_id' => $origin->id,
            'destination_hub_id' => $middle->id,
        ]);

        $incident = $this->incidents->reportOperational($order, $this->checkoutCarrier, $this->payload('missing'));

        try {
            app(ManifestService::class)->addParcel($manifest, $order);
            $this->fail('An active exception must block manifest movement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parcel', $exception->errors());
        }
        $this->assertSame(0, $manifest->items()->count());

        app(ParcelIncidentService::class)->resolve($incident, Admin::factory()->create(), [
            'event' => 'RESUME_ORIGINAL_ROUTE',
            'reason' => 'Parcel located and verified.',
        ]);

        app(ManifestService::class)->addParcel($manifest, $order->fresh());
        $this->assertSame(1, $manifest->items()->count());
    }

    public function test_keep_on_hold_preserves_review_and_keeps_movement_blocked(): void
    {
        $parcel = $this->parcelInCustody();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('failed_transfer'));

        $this->incidents->resolve($incident, Admin::factory()->create(), [
            'event' => 'KEEP_ON_HOLD',
            'reason' => 'No safe alternative exists yet.',
        ]);

        $incident->refresh();
        $this->assertSame(ParcelIncidentStatus::UnderReview, $incident->status);
        $this->assertTrue($incident->isHeld());
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->fresh()->status);

        $this->expectException(ValidationException::class);
        ParcelIncidentService::assertOperationsAllowed($parcel->fresh());
    }

    public function test_provider_cannot_resolve_a_damaged_exception_and_admin_can(): void
    {
        $parcel = $this->parcelInCustody();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('damaged') + [
            'photo' => $this->photo(),
        ]);

        $this->actingAs($this->checkoutCarrier, 'logistics')
            ->post(route('logistics.exceptions.resolve', $incident), [
                'resolution' => 'KEEP_ON_HOLD',
                'resolution_note' => 'Provider cannot close damage.',
            ])->assertForbidden();

        $this->assertSame(ParcelIncidentStatus::Open, $incident->fresh()->status);

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->post(route('admin.incidents.resolve', $incident), [
                'resolution' => 'KEEP_ON_HOLD',
                'resolution_note' => 'Damage confirmed; held for admin review.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(ParcelIncidentStatus::UnderReview, $incident->fresh()->status);
    }

    public function test_resolution_does_not_create_delivery_proof_status_or_custody_records(): void
    {
        $parcel = $this->parcelInCustody();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('missing'));

        $history = StatusHistory::count();
        $proofs = DeliveryProof::count();
        $statusBefore = $parcel->status;

        $this->incidents->resolve($incident, Admin::factory()->create(), [
            'event' => 'RESUME_ORIGINAL_ROUTE',
            'reason' => 'Verified physically at the hub.',
        ]);

        $this->assertSame($history, StatusHistory::count());
        $this->assertSame($proofs, DeliveryProof::count());
        $this->assertSame($statusBefore, $parcel->fresh()->status);
        $this->assertSame((int) $this->checkoutCarrier->id, (int) $parcel->fresh()->custody_provider_id);
    }

    // ── Reroute planning and authorization ─────────────────────────────────

    public function test_authorized_reroute_replaces_future_legs_and_preserves_completed_path(): void
    {
        [$order, $middle, $destination, $alternate] = $this->parcelAtMiddleWithAlternative();

        $incident = $this->incidents->reportOperational($order, $this->checkoutCarrier, $this->payload('inactive_connection'));
        $previousLegs = $order->parcelRoute->fresh()->legs;
        $manifestCount = ManifestItem::count();
        $historyCount = StatusHistory::count();

        $this->actingAs($this->checkoutCarrier, 'logistics')
            ->post(route('logistics.exceptions.resolve', $incident), [
                'resolution' => 'AUTHORIZE_REROUTE',
                'resolution_note' => 'Direct connection is inactive; use the alternate hub.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $route = $order->parcelRoute->fresh();
        $this->assertSame((int) $order->origin_station_hub_id, (int) $route->origin_hub_id);
        $this->assertSame((int) $destination->id, (int) $route->destination_hub_id);
        $this->assertSame([(int) $middle->id, (int) $alternate->id, (int) $destination->id], array_column($route->legs, 'hub_id'));
        $this->assertSame(1, (int) $route->current_leg_index);
        $this->assertNull($route->route_completed_at);

        $audit = ParcelRouteRereoute::sole();
        $this->assertSame($previousLegs, $audit->previous_path);
        $this->assertSame($route->legs, $audit->new_path);
        $this->assertSame([$previousLegs[0]], $audit->completed_legs);
        $this->assertSame('logistics_provider', $audit->authorized_by_type);
        $this->assertSame($this->checkoutCarrier->id, $audit->authorized_by_id);
        $this->assertSame($incident->id, $audit->incident_id);

        $this->assertSame(ParcelIncidentStatus::Resolved, $incident->fresh()->status);
        $this->assertSame('AUTHORIZE_REROUTE', $incident->fresh()->resolution);
        $this->assertSame($previousLegs, $incident->fresh()->resolution_context['previous_path']);

        // Custody and status are untouched; no manifest, scan or proof is fabricated.
        $this->assertSame((int) $middle->id, (int) $order->fresh()->custody_hub_id);
        $this->assertSame((int) $this->checkoutCarrier->id, (int) $order->fresh()->custody_provider_id);
        $this->assertSame(OrderStatus::AtSortingCenter, $order->fresh()->status);
        $this->assertSame($manifestCount, ManifestItem::count());
        $this->assertSame($historyCount, StatusHistory::count());
        $this->assertSame(0, DeliveryProof::count());
    }

    public function test_reroute_without_a_verified_physical_receipt_is_rejected(): void
    {
        [$order, $middle] = $this->parcelAtMiddleWithoutReceipt();

        $incident = $this->incidents->reportOperational($order, $this->checkoutCarrier, $this->payload('missing'));
        $previousLegs = $order->parcelRoute->fresh()->legs;

        $this->actingAs($this->checkoutCarrier, 'logistics')
            ->post(route('logistics.exceptions.resolve', $incident), [
                'resolution' => 'AUTHORIZE_REROUTE',
                'resolution_note' => 'Attempted without a scan.',
            ])->assertSessionHasErrors('route');

        $this->assertSame($previousLegs, $order->parcelRoute->fresh()->legs);
        $this->assertSame(ParcelIncidentStatus::Open, $incident->fresh()->status);
        $this->assertSame(0, ParcelRouteRereoute::count());
    }

    public function test_reroute_is_rejected_while_the_parcel_is_in_transit(): void
    {
        [$order, $middle, $destination, $alternate] = $this->parcelAtMiddleWithAlternative();

        // A dispatched, unreceived manifest means the current leg is physically moving.
        $this->manifestParcel($order, $middle, $alternate);

        $incident = $this->incidents->reportOperational($order->fresh(), $this->checkoutCarrier, $this->payload('failed_transfer'));
        $previousLegs = $order->parcelRoute->fresh()->legs;

        $this->actingAs($this->checkoutCarrier, 'logistics')
            ->post(route('logistics.exceptions.resolve', $incident), [
                'resolution' => 'AUTHORIZE_REROUTE',
                'resolution_note' => 'Reroute while moving.',
            ])->assertSessionHasErrors('route');

        $this->assertSame($previousLegs, $order->parcelRoute->fresh()->legs);
        $this->assertSame(ParcelIncidentStatus::Open, $incident->fresh()->status);
        $this->assertSame(0, ParcelRouteRereoute::count());
    }

    public function test_reroute_without_an_active_alternative_keeps_the_parcel_held_and_rolls_back(): void
    {
        [$order, $middle] = $this->parcelAtMiddleWithReceipt();
        TransportConnection::query()->where('origin_hub_id', $middle->id)->delete();

        $incident = $this->incidents->reportOperational($order, $this->checkoutCarrier, $this->payload('failed_transfer'));
        $previousLegs = $order->parcelRoute->fresh()->legs;

        $this->actingAs($this->checkoutCarrier, 'logistics')
            ->post(route('logistics.exceptions.resolve', $incident), [
                'resolution' => 'AUTHORIZE_REROUTE',
                'resolution_note' => 'No alternative exists.',
            ])->assertSessionHasErrors('route');

        $route = $order->parcelRoute->fresh();
        $this->assertSame($previousLegs, $route->legs);
        $this->assertSame(0, ParcelRouteRereoute::count());
        $this->assertSame(ParcelIncidentStatus::Open, $incident->fresh()->status);

        // The exception still blocks any attempted movement.
        $this->expectException(ValidationException::class);
        ParcelIncidentService::assertOperationsAllowed($order->fresh());
    }

    public function test_reroute_rejects_an_alternative_that_revisits_completed_hubs(): void
    {
        [$order, $middle, $destination] = $this->parcelAtMiddleWithReceipt();
        $origin = $this->originStation();

        // The only path to the destination now loops back through the already
        // completed origin hub.
        TransportConnection::query()->where('origin_hub_id', $middle->id)->delete();
        $this->connect($middle, $origin);
        $this->connect($origin, $destination);

        $incident = $this->incidents->reportOperational($order, $this->checkoutCarrier, $this->payload('wrong_hub'));
        $previousLegs = $order->parcelRoute->fresh()->legs;

        $this->actingAs($this->checkoutCarrier, 'logistics')
            ->post(route('logistics.exceptions.resolve', $incident), [
                'resolution' => 'AUTHORIZE_REROUTE',
                'resolution_note' => 'Attempt a cyclic path.',
            ])->assertSessionHasErrors('route');

        $this->assertSame($previousLegs, $order->parcelRoute->fresh()->legs);
        $this->assertSame(0, ParcelRouteRereoute::count());
        $this->assertSame(ParcelIncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_reroute_is_rejected_for_a_completed_route(): void
    {
        [$order, $middle] = $this->parcelAtMiddleWithReceipt();
        $order->parcelRoute->forceFill(['route_completed_at' => now()])->save();

        $incident = $this->incidents->reportOperational($order, $this->checkoutCarrier, $this->payload('missing'));

        $this->actingAs($this->checkoutCarrier, 'logistics')
            ->post(route('logistics.exceptions.resolve', $incident), [
                'resolution' => 'AUTHORIZE_REROUTE',
                'resolution_note' => 'Completed route.',
            ])->assertSessionHasErrors('route');

        $this->assertNotNull($order->parcelRoute->fresh()->route_completed_at);
        $this->assertSame(0, ParcelRouteRereoute::count());
    }

    public function test_admin_can_authorize_a_reroute_and_the_audit_records_the_admin(): void
    {
        [$order, $middle, $destination, $alternate] = $this->parcelAtMiddleWithAlternative();
        $incident = $this->incidents->reportOperational($order, $this->checkoutCarrier, $this->payload('failed_transfer'));
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.incidents.resolve', $incident), [
                'resolution' => 'AUTHORIZE_REROUTE',
                'resolution_note' => 'Admin approved the alternate hub.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $audit = ParcelRouteRereoute::sole();
        $this->assertSame('admin', $audit->authorized_by_type);
        $this->assertSame($admin->id, $audit->authorized_by_id);
        $this->assertSame([(int) $middle->id, (int) $alternate->id, (int) $destination->id], array_column($order->parcelRoute->fresh()->legs, 'hub_id'));
    }

    public function test_reroute_is_not_authorized_for_lost_or_damaged_exception_types(): void
    {
        $parcel = $this->parcelInCustody();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('damaged') + [
            'photo' => $this->photo(),
        ]);

        $this->expectException(ValidationException::class);
        $this->incidents->resolve($incident, Admin::factory()->create(), [
            'event' => 'AUTHORIZE_REROUTE',
            'reason' => 'Damage is not a routing decision.',
        ]);
    }

    public function test_reroute_revalidates_the_directional_provider_link_at_execution_time(): void
    {
        $partner = $this->makeOperationalCheckoutCarrier('Partner Carrier', '0305401001');
        $partnerStation = LogisticsHub::query()
            ->where('logistics_provider_id', $partner->id)
            ->where('hub_type', LogisticsHub::STATION)
            ->firstOrFail();

        $link = (new LogisticsProviderLink)->forceFill([
            'origin_provider_id' => $this->checkoutCarrier->id,
            'destination_provider_id' => $partner->id,
            'status' => LogisticsProviderLink::ACTIVE,
            'origin_consented_by' => $this->checkoutCarrier->id,
            'origin_consented_at' => now(),
            'destination_consented_by' => $partner->id,
            'destination_consented_at' => now(),
            'approved_by' => Admin::factory()->create()->id,
            'approved_at' => now(),
        ]);
        $link->save();
        $this->activeLink($partner, $this->checkoutCarrier);

        [$order, $middle, $destination] = $this->parcelAtMiddleWithReceipt();
        TransportConnection::query()->where('origin_hub_id', $middle->id)->delete();
        $this->connect($middle, $partnerStation);
        $this->connect($partnerStation, $destination);

        $incident = $this->incidents->reportOperational($order, $this->checkoutCarrier, $this->payload('failed_transfer'));
        $previousLegs = $order->parcelRoute->fresh()->legs;

        // The alternative is valid while the directional link is active.
        $preview = $this->routes->previewReroute($order->fresh(), $this->checkoutCarrier);
        $this->assertSame([(int) $partnerStation->id, (int) $destination->id], array_column($preview['future_path'], 'hub_id'));

        // Disabling the partnership invalidates the connection's `isActive` check.
        $link->forceFill(['status' => LogisticsProviderLink::INACTIVE])->save();

        $this->actingAs($this->checkoutCarrier, 'logistics')
            ->post(route('logistics.exceptions.resolve', $incident), [
                'resolution' => 'AUTHORIZE_REROUTE',
                'resolution_note' => 'Partner link was suspended.',
            ])->assertSessionHasErrors('route');

        $this->assertSame($previousLegs, $order->parcelRoute->fresh()->legs);
        $this->assertSame(0, ParcelRouteRereoute::count());
        $this->assertSame(ParcelIncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_foreign_provider_cannot_report_view_or_resolve_another_providers_exception(): void
    {
        $parcel = $this->parcelInCustody();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('missing'));
        $foreign = $this->makeOperationalCheckoutCarrier('Foreign Carrier', '0305401001');

        $this->actingAs($foreign, 'logistics')
            ->post(route('logistics.exceptions.store', $parcel), $this->payload('wrong_hub'))
            ->assertForbidden();

        $this->actingAs($foreign, 'logistics')
            ->get(route('logistics.exceptions.show', $incident))
            ->assertForbidden();

        $this->actingAs($foreign, 'logistics')
            ->post(route('logistics.exceptions.resolve', $incident), [
                'resolution' => 'RESUME_ORIGINAL_ROUTE',
                'resolution_note' => 'Foreign attempt.',
            ])->assertForbidden();

        $this->assertSame(ParcelIncidentStatus::Open, $incident->fresh()->status);
        $this->assertSame(1, Incidents::count());
    }

    public function test_provider_without_custody_cannot_preview_a_reroute_on_another_providers_station(): void
    {
        [$order] = $this->parcelAtMiddleWithAlternative();
        $foreign = $this->makeOperationalCheckoutCarrier('Foreign Carrier', '0305401001');
        $incident = $this->incidents->reportOperational($order, $this->checkoutCarrier, $this->payload('failed_transfer'));

        $this->actingAs($foreign, 'logistics')
            ->post(route('logistics.exceptions.preview', $incident))
            ->assertForbidden();
    }

    // ── Dashboard coverage ─────────────────────────────────────────────────

    public function test_exception_dashboard_is_provider_scoped_and_filters_active_and_resolved(): void
    {
        $own = $this->parcelInCustody();
        $own->forceFill(['tracking_number' => 'OWN-TRACK-0001'])->save();
        $foreignProvider = $this->makeOperationalCheckoutCarrier('Other Carrier', '0305401001');
        $foreignParcel = $this->parcelInCustody($foreignProvider);
        $foreignParcel->forceFill(['tracking_number' => 'FOREIGN-TRACK-0002'])->save();

        $open = $this->incidents->reportOperational($own, $this->checkoutCarrier, $this->payload('missing'));
        $foreign = $this->incidents->reportOperational($foreignParcel, $foreignProvider, $this->payload('wrong_hub'));
        $resolved = $this->incidents->reportOperational($own, $this->checkoutCarrier, $this->payload('failed_transfer'));
        $this->incidents->resolve($resolved, Admin::factory()->create(), [
            'event' => 'RESUME_ORIGINAL_ROUTE',
            'reason' => 'Verified original route available.',
        ]);

        $active = $this->actingAs($this->checkoutCarrier, 'logistics')
            ->get(route('logistics.exceptions.index'))
            ->assertOk()
            ->assertSee('Parcel exceptions')
            ->assertSee('OWN-TRACK-0001')
            ->assertDontSee('FOREIGN-TRACK-0002');
        $this->assertEqualsCanonicalizing([$open->id], $active->viewData('exceptions')->pluck('id')->all());

        $history = $this->actingAs($this->checkoutCarrier, 'logistics')
            ->get(route('logistics.exceptions.index', ['status' => 'resolved']))
            ->assertOk()
            ->assertSee('OWN-TRACK-0001');
        $this->assertEqualsCanonicalizing([$resolved->id], $history->viewData('exceptions')->pluck('id')->all());

        $filtered = $this->actingAs($this->checkoutCarrier, 'logistics')
            ->get(route('logistics.exceptions.index', ['status' => 'active', 'type' => 'missing']))
            ->assertOk()
            ->assertSee('OWN-TRACK-0001')
            ->assertDontSee('FOREIGN-TRACK-0002');
        $this->assertEqualsCanonicalizing([$open->id], $filtered->viewData('exceptions')->pluck('id')->all());
        $this->assertNotContains($foreign->id, $filtered->viewData('exceptions')->pluck('id')->all());
    }

    public function test_exception_dashboard_rejects_guests_and_shows_verified_location_and_history(): void
    {
        $this->get(route('logistics.exceptions.index'))->assertRedirect();

        [$order, $middle] = $this->parcelAtMiddleWithReceipt();
        $incident = $this->incidents->reportOperational($order, $this->checkoutCarrier, $this->payload('failed_transfer'));

        $this->actingAs($this->checkoutCarrier, 'logistics')
            ->get(route('logistics.exceptions.show', $incident))
            ->assertOk()
            ->assertSee('Exception #'.$incident->id)
            ->assertSee($middle->name)
            ->assertSee('Audit history')
            ->assertSee('Preview valid alternative');
    }

    public function test_operational_report_form_is_available_on_the_existing_parcel_detail_page(): void
    {
        $parcel = $this->parcelInCustody();

        $this->actingAs($this->checkoutCarrier, 'logistics')
            ->get(route('logistics.delivery-monitoring.show', $parcel))
            ->assertOk()
            ->assertSee('Report operational exception')
            ->assertSee('Wrong hub')
            ->assertSee('Failed transfer');
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    public function test_required_missing_exception_code_reuses_existing_incident_type(): void
    {
        $parcel = $this->parcelInCustody();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('MISSING_PARCEL'));
        $this->assertSame('missing', $incident->type);
        $this->assertSame($parcel->custody_hub_id, $parcel->fresh()->custody_hub_id);
        $this->expectException(ValidationException::class);
        ParcelIncidentService::assertOperationsAllowed($parcel);
    }

    public function test_real_dispatch_detects_inactive_connection_and_persists_hold(): void
    {
        $origin = $this->originStation();
        $destination = $this->makeStation('Unavailable destination', '030540500');
        $parcel = $this->orderOnRoute($origin, $destination, [['hub_id' => $destination->id, 'transport_mode' => 'ROAD']]);
        $service = app(ManifestService::class);
        $manifest = $service->create($this->checkoutCarrier, $origin->id, $destination->id);
        $service->addParcel($manifest, $parcel);
        try {
            $service->dispatch($manifest, $this->checkoutCarrier, []);
            $this->fail('A disconnected departure must be held.');
        } catch (ValidationException) {
            $this->assertSame('inactive_connection', Incidents::sole()->type);
            $this->assertSame(ManifestStatus::Draft, $manifest->fresh()->status);
            $this->assertSame($origin->id, $parcel->fresh()->custody_hub_id);
        }
    }

    public function test_manifest_missing_report_creates_hold_without_changing_verified_custody(): void
    {
        $parcel = $this->parcelInCustody();
        $destination = $this->makeStation('Missing receiving hub', '030540500');
        $item = $this->manifestParcel($parcel, $this->originStation(), $destination);
        $this->actingAs($this->checkoutCarrier, 'logistics');
        app(ManifestService::class)->reportIssue($item->manifest, $item, 'missing', 'Parcel absent at receiving scan.', 'logistics', $this->checkoutCarrier->id);
        $this->assertSame('missing', Incidents::sole()->type);
        $this->assertNull($item->fresh()->received_at);
        $this->assertSame($this->originStation()->id, $parcel->fresh()->custody_hub_id);
    }

    public function test_held_exception_can_later_resume_without_new_incident(): void
    {
        $parcel = $this->parcelInCustody();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('missing'));
        $this->incidents->resolveForProvider($incident, $this->checkoutCarrier, ['event' => 'KEEP_ON_HOLD', 'reason' => 'Waiting for inspection.']);
        $this->assertSame(ParcelIncidentStatus::UnderReview, $incident->fresh()->status);
        $this->incidents->resolveForProvider($incident, $this->checkoutCarrier, ['event' => 'RESUME_ORIGINAL_ROUTE', 'reason' => 'Verified parcel located at its hub.']);
        $this->assertSame(ParcelIncidentStatus::Resolved, $incident->fresh()->status);
        ParcelIncidentService::assertOperationsAllowed($parcel);
        $this->assertSame(1, Incidents::count());
    }

    public function test_resume_rejects_inactive_original_route_and_preserves_hold(): void
    {
        [$parcel] = $this->parcelAtMiddleWithAlternative();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('inactive_connection'));
        try {
            $this->incidents->resolveForProvider($incident, $this->checkoutCarrier, ['event' => 'RESUME_ORIGINAL_ROUTE', 'reason' => 'Attempting original path.']);
            $this->fail('An invalid original route cannot resume.');
        } catch (ValidationException) {
            $this->assertTrue($incident->fresh()->isActive());
            $this->assertNull($incident->fresh()->resolved_at);
        }
    }

    public function test_resume_rejects_unverified_custody(): void
    {
        [$parcel] = $this->parcelAtMiddleWithoutReceipt();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('missing'));
        $this->expectException(ValidationException::class);
        $this->incidents->resolveForProvider($incident, $this->checkoutCarrier, ['event' => 'RESUME_ORIGINAL_ROUTE', 'reason' => 'Unverified recovery cannot clear the hold.']);
    }

    public function test_direct_reroute_without_authorized_decision_is_rejected(): void
    {
        [$parcel] = $this->parcelAtMiddleWithAlternative();
        $before = $parcel->parcelRoute->fresh()->legs;
        try {
            $this->routes->rerouteFromCurrent($parcel, 'No actor may be inferred.');
            $this->fail('A reroute requires an authorized incident decision.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame($before, $parcel->parcelRoute->fresh()->legs);
            $this->assertSame(0, ParcelRouteRereoute::count());
        }
    }

    public function test_rerouted_future_manifest_receipt_advances_without_rewriting_completed_prefix(): void
    {
        [$parcel, $middle, $destination, $alternate] = $this->parcelAtMiddleWithAlternative();
        $parcel->forceFill(['tracking_number' => 'TRK-PHASE5-REROUTE'])->save();
        $completed = $parcel->parcelRoute->legs[0];
        $address = $parcel->order->shipping_address;
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('inactive_connection'));
        $this->incidents->resolveForProvider($incident, $this->checkoutCarrier, ['event' => 'AUTHORIZE_REROUTE', 'reason' => 'Verified alternate transfer path.']);
        $service = app(ManifestService::class);
        $manifest = $service->create($this->checkoutCarrier, $middle->id, $alternate->id);
        $item = $service->addParcel($manifest, $parcel->fresh());
        $scan = $service->verifyScan($manifest, $this->checkoutCarrier, $parcel->tracking_number, 'dispatch');
        $manifest = $service->dispatch($manifest, $this->checkoutCarrier, [$item->id => $scan['verification_token']]);
        $scan = $service->verifyScan($manifest, $this->checkoutCarrier, $parcel->tracking_number, 'receive');
        $service->receive($manifest, $this->checkoutCarrier, $item, $scan['verification_token']);
        $route = $parcel->parcelRoute->fresh();
        $this->assertSame($completed, $route->legs[0]);
        $this->assertSame(2, $route->current_leg_index);
        $this->assertSame($alternate->id, $parcel->fresh()->custody_hub_id);
        $this->assertSame($address, $parcel->order->fresh()->shipping_address);
    }

    public function test_existing_exception_details_show_hold_custody_history_and_safe_resolution_controls(): void
    {
        [$parcel] = $this->parcelAtMiddleWithAlternative();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('inactive_connection'));
        $this->actingAs($this->checkoutCarrier, 'logistics')->get(route('logistics.exceptions.show', $incident))
            ->assertOk()->assertSee('On hold')->assertSee('Custody history')->assertSee('Custody transferred to the destination hub.')
            ->assertSee('Preview valid alternative')->assertSee('Confirm resolution');
    }

    public function test_route_preview_is_scoped_to_its_exception_and_shows_only_future_legs(): void
    {
        [$parcel, $middle, $destination, $alternate] = $this->parcelAtMiddleWithAlternative();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('inactive_connection'));
        $this->actingAs($this->checkoutCarrier, 'logistics')->post(route('logistics.exceptions.preview', $incident))
            ->assertRedirect()->assertSessionHas('reroute_preview', fn ($preview) => $preview['incident_id'] === $incident->id
                && array_column($preview['new_path'], 'hub_id') === [$alternate->id, $destination->id]);
        $other = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('missing'));
        $this->get(route('logistics.exceptions.show', $other))->assertViewHas('reroutePreview', null);
    }

    public function test_held_parcel_cannot_bypass_movement_guard_through_direct_route_progression(): void
    {
        [$parcel] = $this->parcelAtMiddleWithReceipt();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('missing'));
        try {
            $this->legs->advanceToNextLeg($parcel);
            $this->fail('A direct service call must respect parcel holds.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parcel', $exception->errors());
            $this->assertSame(1, $parcel->parcelRoute->fresh()->current_leg_index);
            $this->assertTrue($incident->fresh()->isActive());
        }
    }

    public function test_wrong_hub_recovery_uses_real_receiving_then_authorized_reroute_and_final_receipt(): void
    {
        $parcel = $this->parcelInCustody();
        $parcel->forceFill(['tracking_number' => 'TRK-WRONG-HUB-RECOVERY'])->save();
        $origin = $this->originStation();
        $destination = $this->makeStation('Planned delivery hub', '030540600');
        $wrong = $this->makeStation('Actual recovery hub', '030540700');
        $this->connect($origin, $destination);
        $this->connect($wrong, $destination);
        $route = ParcelRoute::create(['seller_order_id' => $parcel->id, 'origin_hub_id' => $origin->id,
            'destination_hub_id' => $destination->id, 'current_leg_index' => 0,
            'legs' => [['hub_id' => $destination->id, 'transport_mode' => 'ROAD']]]);
        $service = app(ManifestService::class);
        $manifest = $service->create($this->checkoutCarrier, $origin->id, $wrong->id);
        $item = $service->addParcel($manifest, $parcel);
        $scan = $service->verifyScan($manifest, $this->checkoutCarrier, $parcel->tracking_number, 'dispatch');
        $manifest = $service->dispatch($manifest, $this->checkoutCarrier, [$item->id => $scan['verification_token']]);
        $scan = $service->verifyScan($manifest, $this->checkoutCarrier, $parcel->tracking_number, 'receive');
        try {
            $service->receive($manifest, $this->checkoutCarrier, $item, $scan['verification_token']);
            $this->fail('A wrong hub must first be reviewed.');
        } catch (ValidationException) {
            $this->assertNull($item->fresh()->received_at);
            $this->assertSame($origin->id, $parcel->fresh()->custody_hub_id);
        }
        $incident = Incidents::sole();
        $this->assertSame('wrong_hub', $incident->type);
        $this->incidents->review($incident, $this->checkoutCarrier, 'Authorize verified physical receipt at the actual hub.');
        $service->receive($manifest, $this->checkoutCarrier, $item, $scan['verification_token']);
        $this->assertSame($wrong->id, $parcel->fresh()->custody_hub_id);
        $this->assertSame(0, $route->fresh()->current_leg_index);
        $this->assertSame(1, $incident->events()->where('event', 'recovery_received')->count());
        $this->assertTrue($incident->fresh()->isActive());
        $this->incidents->resolveForProvider($incident, $this->checkoutCarrier, ['event' => 'AUTHORIZE_REROUTE', 'reason' => 'Recover from the scanned actual hub to the original destination.']);
        $this->assertSame($origin->id, $route->fresh()->origin_hub_id);
        $this->assertSame($wrong->id, $route->fresh()->legs[0]['origin_hub_id']);
        $next = $service->create($this->checkoutCarrier, $wrong->id, $destination->id);
        $nextItem = $service->addParcel($next, $parcel->fresh());
        $scan = $service->verifyScan($next, $this->checkoutCarrier, $parcel->tracking_number, 'dispatch');
        $next = $service->dispatch($next, $this->checkoutCarrier, [$nextItem->id => $scan['verification_token']]);
        $scan = $service->verifyScan($next, $this->checkoutCarrier, $parcel->tracking_number, 'receive');
        $service->receive($next, $this->checkoutCarrier, $nextItem, $scan['verification_token']);
        $this->assertNotNull($route->fresh()->route_completed_at);
        $this->assertSame($destination->id, $parcel->fresh()->custody_hub_id);
        $this->assertSame(2, ManifestItem::whereNotNull('received_at')->count());
    }

    public function test_forged_damage_report_cannot_fabricate_receipt_or_custody(): void
    {
        $parcel = $this->parcelInCustody();
        $destination = $this->makeStation('Damage destination', '030540600');
        $item = $this->manifestParcel($parcel, $this->originStation(), $destination);
        $this->actingAs($this->checkoutCarrier, 'logistics');
        try {
            app(ManifestService::class)->reportIssue($item->manifest, $item, 'damaged', 'Unverified damage receipt.', 'logistics', $this->checkoutCarrier->id, 'forged-token');
            $this->fail('Damage cannot fabricate physical receipt.');
        } catch (ValidationException) {
            $this->assertNull($item->fresh()->received_at);
            $this->assertSame($this->originStation()->id, $parcel->fresh()->custody_hub_id);
            $this->assertSame(0, Incidents::count());
        }
    }

    public function test_verified_damage_receipt_stays_held_until_admin_resumes_original_route(): void
    {
        $origin = $this->originStation();
        $destination = $this->makeStation('Damage delivery hub', '030540600');
        $this->connect($origin, $destination);
        $parcel = $this->orderOnRoute($origin, $destination, [['hub_id' => $destination->id, 'transport_mode' => 'ROAD']]);
        $parcel->forceFill(['tracking_number' => 'TRK-DAMAGE-RECEIPT'])->save();
        $item = $this->manifestParcel($parcel, $origin, $destination);
        $service = app(ManifestService::class);
        $scan = $service->verifyScan($item->manifest, $this->checkoutCarrier, $parcel->tracking_number, 'receive');
        $this->actingAs($this->checkoutCarrier, 'logistics');
        $service->reportIssue($item->manifest, $item, 'damaged', 'Packaging inspected at arrival.', 'logistics', $this->checkoutCarrier->id, $scan['verification_token']);
        $incident = Incidents::sole();
        $this->assertSame('damaged', $incident->type);
        $this->assertSame($destination->id, $parcel->fresh()->custody_hub_id);
        $this->assertNull($parcel->parcelRoute->fresh()->route_completed_at);
        $this->incidents->resolve($incident, Admin::factory()->create(), ['event' => 'RESUME_ORIGINAL_ROUTE', 'reason' => 'Inspection confirms the parcel can continue.']);
        $this->assertSame(ParcelIncidentStatus::Resolved, $incident->fresh()->status);
        $this->assertNotNull($parcel->parcelRoute->fresh()->route_completed_at);
        $this->assertSame(1, ManifestItem::whereNotNull('received_at')->count());
    }

    public function test_cancelled_dispatched_transfer_creates_failed_transfer_hold_without_changing_custody(): void
    {
        $parcel = $this->parcelInCustody();
        $destination = $this->makeStation('Cancelled destination', '030540600');
        $item = $this->manifestParcel($parcel, $this->originStation(), $destination);
        app(ManifestService::class)->cancelManifest($item->manifest, $this->checkoutCarrier, 'Vehicle returned without completing the transfer.');
        $this->assertSame('failed_transfer', Incidents::sole()->type);
        $this->assertSame($this->originStation()->id, $parcel->fresh()->custody_hub_id);
        $this->assertNull($item->fresh()->received_at);
        $this->assertNotNull($item->fresh()->released_at);
        $this->expectException(ValidationException::class);
        ParcelIncidentService::assertOperationsAllowed($parcel);
    }

    public function test_resolving_one_exception_does_not_clear_another_parcel_hold(): void
    {
        $parcel = $this->parcelInCustody();
        $first = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('missing'));
        $other = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('failed_transfer'));
        $this->incidents->resolveForProvider($first, $this->checkoutCarrier, ['event' => 'RESUME_ORIGINAL_ROUTE', 'reason' => 'Located at verified origin.']);
        $this->assertTrue($other->fresh()->isActive());
        $this->expectException(ValidationException::class);
        ParcelIncidentService::assertOperationsAllowed($parcel);
    }

    public function test_stale_receipt_detection_does_not_create_false_wrong_hub_exception_after_progression(): void
    {
        [$parcel, $middle, $destination] = $this->parcelAtMiddleWithReceipt();
        $received = $parcel->manifestItems()->whereNotNull('received_at')->with('manifest')->firstOrFail();
        $this->incidents->detectReceiptDiscrepancy($parcel, $received->manifest->origin_hub_id,
            $middle->id, $this->checkoutCarrier, $received);
        $this->assertSame(0, Incidents::count());
        $this->assertSame(1, $parcel->parcelRoute->fresh()->current_leg_index);
    }

    public function test_archived_provider_cannot_access_exception_dashboard(): void
    {
        $parcel = $this->parcelInCustody();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('missing'));
        $this->checkoutCarrier->forceFill(['archived_at' => now()])->save();
        $this->actingAs($this->checkoutCarrier, 'logistics')->get(route('logistics.exceptions.index'))->assertForbidden();
        $this->get(route('logistics.exceptions.show', $incident))->assertForbidden();
    }

    public function test_legacy_resolved_hold_remains_visible_and_can_receive_later_authorized_resolution(): void
    {
        $parcel = $this->parcelInCustody();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('missing'));
        $incident->forceFill(['status' => ParcelIncidentStatus::Resolved, 'resolution' => 'KEEP_ON_HOLD', 'resolved_at' => now()])->save();
        $this->assertTrue($incident->isActive());
        $this->actingAs($this->checkoutCarrier, 'logistics')->get(route('logistics.exceptions.index'))
            ->assertViewHas('exceptions', fn ($exceptions) => $exceptions->contains('id', $incident->id));
        $this->post(route('logistics.exceptions.resolve', $incident), ['resolution' => 'RESUME_ORIGINAL_ROUTE',
            'resolution_note' => 'Verified original custody and route after historical hold.'])->assertSessionHasNoErrors();
        $this->assertFalse($incident->fresh()->isActive());
        $this->assertNotNull($incident->fresh()->reviewed_at);
        ParcelIncidentService::assertOperationsAllowed($parcel);
    }

    public function test_reroute_audit_failure_rolls_back_route_decision_and_preserves_hold(): void
    {
        [$parcel] = $this->parcelAtMiddleWithAlternative();
        $incident = $this->incidents->reportOperational($parcel, $this->checkoutCarrier, $this->payload('inactive_connection'));
        $before = $parcel->parcelRoute->fresh()->legs;
        Event::listen('eloquent.creating: '.ParcelRouteRereoute::class, function (): void {
            throw new \RuntimeException('Audit storage unavailable.');
        });
        try {
            $this->incidents->resolveForProvider($incident, $this->checkoutCarrier, ['event' => 'AUTHORIZE_REROUTE', 'reason' => 'An unaudited reroute must never commit.']);
            $this->fail('The route and its audit must commit together.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit storage unavailable.', $exception->getMessage());
            $this->assertSame($before, $parcel->parcelRoute->fresh()->legs);
            $this->assertSame(1, $parcel->parcelRoute->fresh()->current_leg_index);
            $this->assertSame(ParcelIncidentStatus::Open, $incident->fresh()->status);
            $this->assertNull($incident->fresh()->resolution);
            $this->assertSame(0, ParcelRouteRereoute::count());
        } finally {
            Event::forget('eloquent.creating: '.ParcelRouteRereoute::class);
        }
    }

    private function payload(string $type): array
    {
        return [
            'type' => $type,
            'reason' => 'Operational review needed for '.$type.'.',
            'description' => 'Recorded by the provider from verified hub operations.',
        ];
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('damage.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII='
        ));
    }

    private function originStation(): LogisticsHub
    {
        return LogisticsHub::query()
            ->where('logistics_provider_id', $this->checkoutCarrier->id)
            ->where('hub_type', LogisticsHub::STATION)
            ->firstOrFail();
    }

    private function makeStation(string $name, string $cityCode): LogisticsHub
    {
        $origin = $this->originStation();

        return LogisticsHub::create([
            'logistics_provider_id' => $this->checkoutCarrier->id,
            'parent_hub_id' => $origin->parent_hub_id,
            'name' => $name,
            'hub_type' => LogisticsHub::STATION,
            'region_code' => $origin->region_code,
            'province_code' => $origin->province_code,
            'province_name' => $origin->province_name,
            'city_municipality_code' => $cityCode,
            'municipality' => $name,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);
    }

    private function activeLink(LogisticsProvider $from, LogisticsProvider $to): LogisticsProviderLink
    {
        $link = (new LogisticsProviderLink)->forceFill([
            'origin_provider_id' => $from->id,
            'destination_provider_id' => $to->id,
            'status' => LogisticsProviderLink::ACTIVE,
            'origin_consented_by' => $from->id,
            'origin_consented_at' => now(),
            'destination_consented_by' => $to->id,
            'destination_consented_at' => now(),
            'approved_by' => Admin::factory()->create()->id,
            'approved_at' => now(),
        ]);
        $link->save();

        return $link;
    }

    private function connect(LogisticsHub $from, LogisticsHub $to): void
    {
        TransportConnection::create([
            'origin_hub_id' => $from->id,
            'destination_hub_id' => $to->id,
            'transport_mode' => TransportConnection::ROAD,
            'is_active' => true,
        ]);
    }

    /** A parcel with verified physical custody but no saved multi-leg route. */
    private function parcelInCustody(?LogisticsProvider $provider = null): SellerOrder
    {
        $provider ??= $this->checkoutCarrier;
        $hub = LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)
            ->firstOrFail();

        $order = SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id])->id,
            'seller_id' => $this->seller->id,
            'carrier_provider_id' => $provider->id,
        ]);
        $order->forceFill([
            'status' => OrderStatus::AtSortingCenter->value,
            'custody_hub_id' => $hub->id,
            'custody_provider_id' => $provider->id,
            'origin_station_hub_id' => $hub->id,
            'handover_at' => now(),
        ])->save();

        StatusHistory::record($order, OrderStatus::PickedUp, OrderStatus::AtSortingCenter,
            actor: OrderActor::Logistics, actorId: $provider->id);

        return $order->fresh();
    }

    /** @param  list<array{hub_id: int, transport_mode: string}>  $legs */
    private function orderOnRoute(LogisticsHub $origin, LogisticsHub $destination, array $legs): SellerOrder
    {
        $order = SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id])->id,
            'seller_id' => $this->seller->id,
            'carrier_provider_id' => $this->checkoutCarrier->id,
        ]);
        $order->forceFill([
            'status' => OrderStatus::AtSortingCenter->value,
            'custody_hub_id' => $origin->id,
            'custody_provider_id' => $this->checkoutCarrier->id,
            'origin_station_hub_id' => $origin->id,
            'handover_at' => now(),
        ])->save();
        StatusHistory::record($order, OrderStatus::PickedUp, OrderStatus::AtSortingCenter,
            actor: OrderActor::Logistics, actorId: $this->checkoutCarrier->id);

        ParcelRoute::create([
            'seller_order_id' => $order->id,
            'origin_hub_id' => $origin->id,
            'destination_hub_id' => $destination->id,
            'legs' => $legs,
            'current_leg_index' => 0,
        ]);

        return $order->fresh();
    }

    /** @return array{SellerOrder, LogisticsHub, LogisticsHub} */
    private function parcelAtMiddleWithReceipt(): array
    {
        $origin = $this->originStation();
        $middle = $this->makeStation('Middle Hub', '030540200');
        $destination = $this->makeStation('Destination Hub', '030540300');
        $this->connect($origin, $middle);
        $this->connect($middle, $destination);

        $order = $this->orderOnRoute($origin, $destination, [
            ['hub_id' => $middle->id, 'transport_mode' => TransportConnection::ROAD],
            ['hub_id' => $destination->id, 'transport_mode' => TransportConnection::ROAD],
        ]);

        $this->receiveAt($this->manifestParcel($order, $origin, $middle), $order, $middle);
        $this->legs->advanceToNextLeg($order);
        $this->assertSame(1, (int) $order->parcelRoute->fresh()->current_leg_index);

        return [$order->fresh(), $middle, $destination];
    }

    /** @return array{SellerOrder, LogisticsHub, LogisticsHub} */
    private function parcelAtMiddleWithoutReceipt(): array
    {
        $origin = $this->originStation();
        $middle = $this->makeStation('Middle Hub', '030540200');
        $destination = $this->makeStation('Destination Hub', '030540300');
        $this->connect($origin, $middle);
        $this->connect($middle, $destination);

        $order = $this->orderOnRoute($origin, $destination, [
            ['hub_id' => $middle->id, 'transport_mode' => TransportConnection::ROAD],
            ['hub_id' => $destination->id, 'transport_mode' => TransportConnection::ROAD],
        ]);

        // Custody claimed at the middle hub with no receiving scan.
        $order->forceFill([
            'custody_hub_id' => $middle->id,
            'custody_provider_id' => $this->checkoutCarrier->id,
        ])->save();
        $order->parcelRoute->forceFill(['current_leg_index' => 1])->save();

        return [$order->fresh(), $middle, $destination];
    }

    /** @return array{SellerOrder, LogisticsHub, LogisticsHub, LogisticsHub} */
    private function parcelAtMiddleWithAlternative(): array
    {
        [$order, $middle, $destination] = $this->parcelAtMiddleWithReceipt();

        $alternate = $this->makeStation('Alternate Hub', '030540400');
        TransportConnection::query()->where('origin_hub_id', $middle->id)->delete();
        $this->connect($middle, $alternate);
        $this->connect($alternate, $destination);

        return [$order, $middle, $destination, $alternate];
    }

    private function manifestParcel(SellerOrder $order, LogisticsHub $from, LogisticsHub $to): ManifestItem
    {
        $manifest = Manifest::create([
            'logistics_provider_id' => $this->checkoutCarrier->id,
            'origin_hub_id' => $from->id,
            'destination_hub_id' => $to->id,
            'dispatched_at' => now(),
        ]);
        $manifest->forceFill(['status' => ManifestStatus::Dispatched->value])->save();

        return ManifestItem::create(['manifest_id' => $manifest->id, 'seller_order_id' => $order->id]);
    }

    private function receiveAt(ManifestItem $item, SellerOrder $order, LogisticsHub $hub): void
    {
        $item->markReceived('logistics', (int) $this->checkoutCarrier->id);
        $order->forceFill([
            'custody_provider_id' => $this->checkoutCarrier->id,
            'custody_hub_id' => $hub->id,
        ])->save();
    }
}
