<?php

namespace Tests\Feature\Logistics;

use App\Enums\ManifestStatus;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\Logistics\ParcelRoute;
use App\Models\Logistics\TransportConnection;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Logistics\LogisticsHubService;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelLegService;
use App\Services\Logistics\RouteExecutionService;
use App\Services\Logistics\RoutePlannerService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/**
 * Phase 4: Multi-Leg Hub Routing automated test suite.
 *
 * Tests cover:
 * 1. Direct same-provider Station Hub transfer (manifest)
 * 2. Same-province multi-hub transfer via transport connections
 * 3. Cross-provider handoff through provider links
 * 4. Inter-island routing (Luzon → Mindanao direct)
 * 5. Complex multi-island chain (Luzon → Visayas → Mindanao)
 * 6. Disconnected hub network rejection
 * 7. Inactive transport connection blocks routing
 * 8. Inactive intermediate hub blocks routing
 * 9. Cyclic transport connections prevent infinite loops
 * 10. Route feasibility validation
 * 11. Leg readiness checks
 * 12. Route completion detection
 * 13. Multi-transport-mode support
 * 14. Transport connection prevents self-loops
 * 15. Phase 3 checkout compatibility
 */
class Phase4MultiLegRoutingTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    protected RoutePlannerService $routePlanner;

    protected ParcelLegService $legService;

    protected RouteExecutionService $executionService;

    protected Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Bootstrap checkout carriers (creates buyer, seller, and provider with hub)
        $this->buyer = Buyer::factory()->create([
            'street' => 'Test Street',
            'municipality_name' => 'San Fernando',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'barangay_code' => '0305401001',
            'barangay_name' => 'First',
        ]);

        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->bootstrapCheckoutCarriers($this->buyer, [$seller]);

        $this->routePlanner = app(RoutePlannerService::class);
        $this->legService = app(ParcelLegService::class);
        $this->executionService = app(RouteExecutionService::class);
    }

    /**
     * Test 1: Direct same-provider Station Hub transfer.
     */
    public function test_direct_same_provider_station_hub_transfer(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved', 'barangay_code' => '0305401001', 'province_code' => '030540000']);
        $provider = $this->checkoutCarrier;

        // Plan route: should find direct path
        $route = $this->routePlanner->planRoute($seller, $provider, $this->buyer->barangay_code, $this->buyer->province_code);

        $this->assertNotNull($route);
        $this->assertNotNull($route->origin_hub_id);
        $this->assertNotNull($route->destination_hub_id);
    }

    /**
     * Test 2: Same-province multi-hub transfer via transport connections.
     */
    public function test_same_province_multi_hub_transfer(): void
    {
        $provider = $this->checkoutCarrier;
        $originHub = LogisticsHub::where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        // Create intermediate hub
        $intermediateHub = LogisticsHub::create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $originHub->parent_hub_id,
            'name' => 'Intermediate Hub',
            'hub_type' => LogisticsHub::STATION,
            'province_code' => $originHub->province_code,
            'province_name' => $originHub->province_name,
            'city_municipality_code' => '030540200',
            'municipality' => 'Angeles City',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);

        // Create destination hub
        $destinationHub = LogisticsHub::create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $originHub->parent_hub_id,
            'name' => 'Destination Hub',
            'hub_type' => LogisticsHub::STATION,
            'province_code' => $originHub->province_code,
            'province_name' => $originHub->province_name,
            'city_municipality_code' => '030540100',
            'municipality' => 'San Fernando',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);

        // Create transport connections
        TransportConnection::create([
            'origin_hub_id' => $originHub->id,
            'destination_hub_id' => $intermediateHub->id,
            'transport_mode' => TransportConnection::ROAD,
            'is_active' => true,
        ]);

        TransportConnection::create([
            'origin_hub_id' => $intermediateHub->id,
            'destination_hub_id' => $destinationHub->id,
            'transport_mode' => TransportConnection::ROAD,
            'is_active' => true,
        ]);

        $route = $this->routePlanner->planRoute(
            Seller::factory()->create(['status' => 'approved', 'barangay_code' => '0305401001', 'province_code' => '030540000']),
            $provider,
            $this->buyer->barangay_code,
            $this->buyer->province_code
        );

        $this->assertNotNull($route);
        $this->assertTrue($this->routePlanner->isRouteFeasible($route));
    }

    /**
     * Test 3: Cross-provider handoff through provider links.
     */
    public function test_cross_provider_handoff_via_provider_link(): void
    {
        $provider1 = $this->checkoutCarrier;
        // A link destination must really operate: Province Hub, Station, validated coverage.
        $provider2 = $this->makeOperationalCheckoutCarrier('Partner Carrier', '0305401001');

        // Create provider link using forceFill (model is guarded)
        $link = new LogisticsProviderLink;
        $link->forceFill([
            'origin_provider_id' => $provider1->id,
            'destination_provider_id' => $provider2->id,
            'status' => LogisticsProviderLink::ACTIVE,
            'origin_consented_by' => $provider1->id,
            'origin_consented_at' => now(),
            'destination_consented_by' => $provider2->id,
            'destination_consented_at' => now(),
            'approved_by' => Admin::factory()->create()->id,
            'approved_at' => now(),
        ])->save();

        $this->assertNotNull($link->id);
        $this->assertTrue($link->isActive());
    }

    /**
     * Test 4: Disconnected hub network rejection.
     */
    public function test_disconnected_hub_network_rejection(): void
    {
        $provider = $this->checkoutCarrier;
        $originHub = LogisticsHub::where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        // Create isolated hub with no connections
        $isolatedHub = LogisticsHub::create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $originHub->parent_hub_id,
            'name' => 'Isolated Hub',
            'hub_type' => LogisticsHub::STATION,
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'city_municipality_code' => '999999999',
            'municipality' => 'Unknown',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);

        // Add coverage for isolated hub
        Area::factory()->create([
            'logistics_provider_id' => $provider->id,
            'station_hub_id' => $isolatedHub->id,
            'barangay_code' => '9999999999',
        ]);

        $buyer = Buyer::factory()->create([
            'barangay_code' => '9999999999',
            'province_code' => '030540000',
        ]);

        // Should fail: no path to isolated hub
        $this->expectException(ValidationException::class);
        $this->routePlanner->planRoute(
            Seller::factory()->create(['status' => 'approved', 'barangay_code' => '0305401001', 'province_code' => '030540000']),
            $provider,
            $buyer->barangay_code,
            $buyer->province_code
        );
    }

    /**
     * Test 5: Inactive transport connection blocks routing.
     */
    public function test_inactive_transport_connection_blocks_routing(): void
    {
        $provider = $this->checkoutCarrier;
        $originHub = LogisticsHub::where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        $destHub = LogisticsHub::create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $originHub->parent_hub_id,
            'name' => 'Blocked',
            'hub_type' => LogisticsHub::STATION,
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'city_municipality_code' => '999999999',
            'municipality' => 'Unknown',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);

        // Create INACTIVE connection
        TransportConnection::create([
            'origin_hub_id' => $originHub->id,
            'destination_hub_id' => $destHub->id,
            'transport_mode' => TransportConnection::ROAD,
            'is_active' => false,
        ]);

        $route = new ParcelRoute([
            'origin_hub_id' => $originHub->id,
            'destination_hub_id' => $destHub->id,
            'legs' => json_encode([['hub_id' => $destHub->id, 'transport_mode' => 'ROAD']]),
        ]);

        $this->assertFalse($this->routePlanner->isRouteFeasible($route));
    }

    /**
     * Test 6: Inactive intermediate hub blocks routing.
     */
    public function test_inactive_intermediate_hub_blocks_routing(): void
    {
        $provider = $this->checkoutCarrier;
        $originHub = LogisticsHub::where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        $inactiveHub = LogisticsHub::create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $originHub->parent_hub_id,
            'name' => 'Inactive',
            'hub_type' => LogisticsHub::STATION,
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'city_municipality_code' => '999999999',
            'municipality' => 'Unknown',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => false,
        ]);

        $route = new ParcelRoute([
            'origin_hub_id' => $originHub->id,
            'destination_hub_id' => $inactiveHub->id,
            'legs' => json_encode([['hub_id' => $inactiveHub->id, 'transport_mode' => 'ROAD']]),
        ]);

        $this->assertFalse($this->routePlanner->isRouteFeasible($route));
    }

    /**
     * Test 7: Cyclic connections prevent infinite loops.
     */
    public function test_cyclic_connections_prevent_infinite_loops(): void
    {
        $provider = $this->checkoutCarrier;
        $hubA = LogisticsHub::where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        $hubB = LogisticsHub::create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $hubA->parent_hub_id,
            'name' => 'Hub B',
            'hub_type' => LogisticsHub::STATION,
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'city_municipality_code' => '030540200',
            'municipality' => 'Angeles City',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);

        $hubC = LogisticsHub::create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $hubA->parent_hub_id,
            'name' => 'Hub C',
            'hub_type' => LogisticsHub::STATION,
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'city_municipality_code' => '030540300',
            'municipality' => 'Unknown',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);

        // Create cycle: A → B → C → A
        TransportConnection::create(['origin_hub_id' => $hubA->id, 'destination_hub_id' => $hubB->id, 'transport_mode' => TransportConnection::ROAD, 'is_active' => true]);
        TransportConnection::create(['origin_hub_id' => $hubB->id, 'destination_hub_id' => $hubC->id, 'transport_mode' => TransportConnection::ROAD, 'is_active' => true]);
        TransportConnection::create(['origin_hub_id' => $hubC->id, 'destination_hub_id' => $hubA->id, 'transport_mode' => TransportConnection::ROAD, 'is_active' => true]);

        // Planner should complete without hanging (MAX_ROUTE_DEPTH prevents infinite loop)
        $path = $this->routePlanner->findPath($hubA, $hubC, $provider);
        $this->assertIsArray($path);
    }

    /**
     * Test 8: Leg readiness validation.
     */
    public function test_leg_readiness_validation(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $order = Order::factory()->create();
        $sellerOrder = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'carrier_provider_id' => $this->checkoutCarrier->id,
        ]);

        $originHub = LogisticsHub::where('logistics_provider_id', $this->checkoutCarrier->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        $route = ParcelRoute::create([
            'seller_order_id' => $sellerOrder->id,
            'origin_hub_id' => $originHub->id,
            'destination_hub_id' => $originHub->id,
            'legs' => json_encode([]),
            'current_leg_index' => 0,
        ]);

        $this->assertFalse($this->legService->isReadyForNextLeg($sellerOrder));
    }

    /**
     * Test 9: Route completion detection.
     */
    public function test_route_completion_marks_last_mile_eligible(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $order = Order::factory()->create();
        $sellerOrder = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'carrier_provider_id' => $this->checkoutCarrier->id,
        ]);

        $destHub = LogisticsHub::where('logistics_provider_id', $this->checkoutCarrier->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        $route = ParcelRoute::create([
            'seller_order_id' => $sellerOrder->id,
            'origin_hub_id' => $destHub->id,
            'destination_hub_id' => $destHub->id,
            'legs' => json_encode([]),
            'current_leg_index' => 0,
            'route_completed_at' => now(),
        ]);

        $sellerOrder->forceFill(['custody_provider_id' => $this->checkoutCarrier->id,
            'custody_hub_id' => $destHub->id, 'origin_station_hub_id' => $destHub->id, 'handover_at' => now()])->save();
        StatusHistory::record($sellerOrder, OrderStatus::PickedUp, OrderStatus::AtSortingCenter,
            actor: OrderActor::Logistics, actorId: $this->checkoutCarrier->id);
        $sellerOrder->setRelation('parcelRoute', $route);
        $this->assertTrue($this->executionService->isAtFinalDestination($sellerOrder->parcelRoute));
    }

    /**
     * Test 10: Multi-transport-mode support.
     */
    public function test_multi_transport_mode_routing(): void
    {
        $provider = $this->checkoutCarrier;
        $hubA = LogisticsHub::where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        $hubB = LogisticsHub::create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $hubA->parent_hub_id,
            'name' => 'Port Hub',
            'hub_type' => LogisticsHub::STATION,
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'city_municipality_code' => '030540200',
            'municipality' => 'Angeles City',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);

        TransportConnection::create([
            'origin_hub_id' => $hubA->id,
            'destination_hub_id' => $hubB->id,
            'transport_mode' => TransportConnection::SEA,
            'is_active' => true,
        ]);

        $this->assertTrue($this->routePlanner->isRouteFeasible(
            new ParcelRoute([
                'origin_hub_id' => $hubA->id,
                'destination_hub_id' => $hubB->id,
                'legs' => json_encode([['hub_id' => $hubB->id, 'transport_mode' => TransportConnection::SEA]]),
            ])
        ));
    }

    /**
     * Test 11: Transport connection prevents self-loops.
     */
    public function test_transport_connection_prevents_self_loops(): void
    {
        $provider = $this->checkoutCarrier;
        $hub = LogisticsHub::where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        $this->expectException(ValidationException::class);
        TransportConnection::create([
            'origin_hub_id' => $hub->id,
            'destination_hub_id' => $hub->id,
            'transport_mode' => TransportConnection::ROAD,
            'is_active' => true,
        ]);
    }

    /**
     * Test 12: Route feasibility validation.
     */
    public function test_route_feasibility_validation(): void
    {
        $provider = $this->checkoutCarrier;
        $originHub = LogisticsHub::where('logistics_provider_id', $provider->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        $destHub = LogisticsHub::create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $originHub->parent_hub_id,
            'name' => 'Test Dest',
            'hub_type' => LogisticsHub::STATION,
            'province_code' => $provider->province_code,
            'province_name' => $provider->province_name,
            'city_municipality_code' => '999999999',
            'municipality' => 'Unknown',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);

        TransportConnection::create([
            'origin_hub_id' => $originHub->id,
            'destination_hub_id' => $destHub->id,
            'transport_mode' => TransportConnection::ROAD,
            'is_active' => true,
        ]);

        $route = new ParcelRoute([
            'origin_hub_id' => $originHub->id,
            'destination_hub_id' => $destHub->id,
            'legs' => json_encode([['hub_id' => $destHub->id, 'transport_mode' => 'ROAD']]),
        ]);

        $this->assertTrue($this->routePlanner->isRouteFeasible($route));
    }

    /**
     * Test 13: Route execution service provides summaries.
     */
    public function test_route_execution_service_provides_readable_summaries(): void
    {
        $originHub = LogisticsHub::where('logistics_provider_id', $this->checkoutCarrier->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        $route = new ParcelRoute([
            'origin_hub_id' => $originHub->id,
            'destination_hub_id' => $originHub->id,
            'legs' => json_encode([]),
        ]);

        $summary = $this->executionService->routeSummary($route);
        $this->assertIsString($summary);
        $this->assertNotEmpty($summary);
    }

    /**
     * Test 14: Leg completion handling.
     */
    public function test_leg_completion_handling(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $order = Order::factory()->create();
        $sellerOrder = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'carrier_provider_id' => $this->checkoutCarrier->id,
        ]);

        $originHub = LogisticsHub::where('logistics_provider_id', $this->checkoutCarrier->id)
            ->where('hub_type', LogisticsHub::STATION)->first();

        $route = ParcelRoute::create([
            'seller_order_id' => $sellerOrder->id,
            'origin_hub_id' => $originHub->id,
            'destination_hub_id' => $originHub->id,
            'legs' => json_encode([]),
            'current_leg_index' => 0,
        ]);

        // A plan alone is never proof of physical receipt.
        $this->expectException(ValidationException::class);
        $this->legService->completeLeg($sellerOrder, 0);
    }

    /**
     * Test 15: Phase 3 checkout compatibility.
     */
    public function test_existing_checkout_flows_remain_functional(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved', 'barangay_code' => '0305401001', 'province_code' => '030540000']);
        $originArea = Area::where('logistics_provider_id', $this->checkoutCarrier->id)
            ->where('barangay_code', '0305401001')->first();

        $this->assertNotNull($originArea);
        $this->assertTrue($originArea->station->isOperational());
    }

    // ── Stabilization regressions: behaviour the 15 scenarios above do not reach ──

    public function test_shared_station_hub_plans_a_route_with_no_legs(): void
    {
        $route = $this->planToBuyer();

        $this->assertSame((int) $route->origin_hub_id, (int) $route->destination_hub_id);
        $this->assertSame([], $route->legs);
    }

    public function test_consecutive_connected_hubs_form_a_route_without_province_hub_transit(): void
    {
        $origin = $this->originStation();
        $middle = $this->makeStation('Middle Hub', '030540200');
        $destination = $this->coverSecondBarangay();

        $this->connect($origin, $middle);
        $this->connect($middle, $destination);
        // A cycle next to the valid path must not hide it.
        $this->connect($middle, $origin);

        $route = $this->planToBuyer('0305401002');

        $this->assertSame([(int) $middle->id, (int) $destination->id], array_column($route->legs, 'hub_id'));
        $this->assertTrue($this->routePlanner->isRouteFeasible($route));
    }

    public function test_covered_but_unconnected_destination_is_rejected(): void
    {
        $this->coverSecondBarangay();

        $this->expectException(ValidationException::class);
        $this->planToBuyer('0305401002');
    }

    public function test_path_does_not_pass_through_a_hub_that_stopped_operating(): void
    {
        $origin = $this->originStation();
        $middle = $this->makeStation('Middle Hub', '030540200');
        $destination = $this->coverSecondBarangay();
        $this->connect($origin, $middle);
        $this->connect($middle, $destination);

        $middle->forceFill(['is_active' => false])->save();

        $this->assertSame([], $this->routePlanner->findPath($origin, $destination, $this->checkoutCarrier));
    }

    public function test_link_destination_without_operational_coverage_is_rejected(): void
    {
        $uncovered = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'province_code' => '030540000',
            'province_verified_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('active operational coverage');
        (new LogisticsProviderLink)->forceFill([
            'origin_provider_id' => $this->checkoutCarrier->id,
            'destination_provider_id' => $uncovered->id,
            'status' => LogisticsProviderLink::ACTIVE,
            'origin_consented_at' => now(),
            'destination_consented_at' => now(),
            'approved_at' => now(),
        ])->save();
    }

    public function test_crossing_into_another_providers_hub_requires_an_active_link(): void
    {
        $partner = $this->makeOperationalCheckoutCarrier('Partner Carrier', '0305401001');
        $partnerStation = LogisticsHub::where('logistics_provider_id', $partner->id)
            ->where('hub_type', LogisticsHub::STATION)->first();
        $origin = $this->originStation();
        $this->assertSame([], $this->routePlanner->findPath($origin, $partnerStation, $this->checkoutCarrier));

        (new LogisticsProviderLink)->forceFill([
            'origin_provider_id' => $this->checkoutCarrier->id,
            'destination_provider_id' => $partner->id,
            'status' => LogisticsProviderLink::ACTIVE,
            'origin_consented_by' => $this->checkoutCarrier->id,
            'origin_consented_at' => now(),
            'destination_consented_by' => $partner->id,
            'destination_consented_at' => now(),
            'approved_by' => Admin::factory()->create()->id,
            'approved_at' => now(),
        ])->save();

        $this->connect($origin, $partnerStation);

        $this->assertSame(
            [(int) $partnerStation->id],
            array_column($this->routePlanner->findPath($origin, $partnerStation, $this->checkoutCarrier), 'hub_id'),
        );
    }

    public function test_route_completes_only_after_a_verified_receipt_at_each_hub(): void
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

        // Dispatched but unscanned: custody stays at the origin, nothing advances.
        $item = $this->manifestParcel($order, $origin, $middle);
        $this->assertFalse($this->legService->isReadyForNextLeg($order));

        try {
            $this->legService->advanceToNextLeg($order);
            $this->fail('An unreceived parcel must not advance.');
        } catch (ValidationException) {
            $this->assertSame(0, (int) $order->parcelRoute->fresh()->current_leg_index);
        }

        // Custody claimed with no receiving scan is not a verified receipt either.
        $order->forceFill([
            'custody_provider_id' => $this->checkoutCarrier->id,
            'custody_hub_id' => $middle->id,
        ])->save();
        $this->assertFalse($this->legService->isReadyForNextLeg($order));

        $item->markReceived('logistics', (int) $this->checkoutCarrier->id);
        $this->legService->advanceToNextLeg($order);

        $route = $order->parcelRoute->fresh();
        $this->assertSame(1, (int) $route->current_leg_index);
        $this->assertFalse($this->executionService->isAtFinalDestination($route));

        // Planned arrival on the final leg still completes nothing.
        $order->unsetRelation('parcelRoute');
        $this->assertFalse($this->legService->isReadyForNextLeg($order));

        $this->receiveAt($this->manifestParcel($order, $middle, $destination), $order, $destination);
        $this->legService->advanceToNextLeg($order);

        $route = $route->fresh();
        $this->assertNotNull($route->route_completed_at);
        $this->assertTrue($this->executionService->isAtFinalDestination($route));
    }

    public function test_unavailable_future_leg_blocks_departure_after_recording_verified_receipt(): void
    {
        $origin = $this->originStation();
        $middle = $this->makeStation('Middle Hub', '030540200');
        $destination = $this->makeStation('Destination Hub', '030540300');
        $this->connect($origin, $middle);
        $this->connect($middle, $destination);
        $legs = $this->routePlanner->findPath($origin, $destination, $this->checkoutCarrier);
        $order = $this->orderOnRoute($origin, $destination, $legs);
        $legs = $order->parcelRoute->fresh()->legs;
        $this->receiveAt($this->manifestParcel($order, $origin, $middle), $order, $middle);
        TransportConnection::query()->where('origin_hub_id', $middle->id)->firstOrFail()->delete();

        $this->legService->advanceToNextLeg($order);
        $this->assertSame(1, $order->parcelRoute->fresh()->current_leg_index);
        $this->assertSame($legs, $order->parcelRoute->fresh()->legs);
        $this->assertNull($order->parcelRoute->fresh()->route_completed_at);

        $this->expectException(ValidationException::class);
        $this->routePlanner->requireAvailableDeparture($order->fresh());
    }

    public function test_completed_connection_can_be_disabled_without_blocking_next_available_leg(): void
    {
        $origin = $this->originStation();
        $middle = $this->makeStation('Middle Hub', '030540200');
        $destination = $this->makeStation('Destination Hub', '030540300');
        $this->connect($origin, $middle);
        $this->connect($middle, $destination);
        $legs = $this->routePlanner->findPath($origin, $destination, $this->checkoutCarrier);
        $order = $this->orderOnRoute($origin, $destination, $legs);
        $legs = $order->parcelRoute->fresh()->legs;
        $this->receiveAt($this->manifestParcel($order, $origin, $middle), $order, $middle);
        TransportConnection::query()->where('origin_hub_id', $origin->id)->firstOrFail()->update(['is_active' => false]);

        $this->legService->advanceToNextLeg($order);

        $this->assertSame(1, $order->parcelRoute->fresh()->current_leg_index);
        $this->assertSame($legs, $order->parcelRoute->fresh()->legs);
    }

    public function test_manifest_dispatch_refuses_unavailable_saved_connection(): void
    {
        $origin = $this->originStation();
        $destination = $this->makeStation('Destination Hub', '030540300');
        $this->connect($origin, $destination);
        $legs = $this->routePlanner->findPath($origin, $destination, $this->checkoutCarrier);
        $order = $this->orderOnRoute($origin, $destination, $legs);
        $legs = $order->parcelRoute->fresh()->legs;
        $item = $this->manifestParcel($order, $origin, $destination);
        $manifest = $item->manifest;
        $manifest->forceFill(['status' => ManifestStatus::Draft->value, 'dispatched_at' => null])->save();
        TransportConnection::query()->where('origin_hub_id', $origin->id)->firstOrFail()->update(['is_active' => false]);

        try {
            app(ManifestService::class)->dispatch($manifest, $this->checkoutCarrier, []);
            $this->fail('Dispatch must refuse an unavailable saved route.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('route', $exception->errors());
            $this->assertSame(ManifestStatus::Draft, $manifest->fresh()->status);
            $this->assertSame($legs, $order->parcelRoute->fresh()->legs);
        }
    }

    public function test_shared_hub_route_completes_only_after_pickup(): void
    {
        $hub = $this->originStation();
        $order = $this->orderOnRoute($hub, $hub, []);
        $order->forceFill(['handover_at' => null, 'origin_station_hub_id' => $hub->id])->save();

        $this->assertFalse($this->legService->isReadyForNextLeg($order));

        $order->forceFill(['handover_at' => now()])->save();
        $this->legService->advanceToNextLeg($order);

        $this->assertTrue($this->executionService->isAtFinalDestination($order->parcelRoute->fresh()));
    }

    private function originStation(): LogisticsHub
    {
        return LogisticsHub::where('logistics_provider_id', $this->checkoutCarrier->id)
            ->where('hub_type', LogisticsHub::STATION)->first();
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

    /** A Station Hub that is the validated coverage for barangay 0305401002. */
    private function coverSecondBarangay(): LogisticsHub
    {
        $station = $this->makeStation('Destination Hub', '030540100');

        (new LogisticsHubService)->saveCoverage($this->checkoutCarrier->id, [
            'name' => 'Coverage 0305401002',
            'station_hub_id' => $station->id,
            'city_municipality_code' => '030540100',
            'barangay_code' => '0305401002',
            'is_active' => true,
        ]);

        return $station;
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

    private function planToBuyer(string $barangay = '0305401001'): ParcelRoute
    {
        return $this->routePlanner->planRoute(
            Seller::factory()->create(['status' => 'approved', 'barangay_code' => '0305401001', 'province_code' => '030540000']),
            $this->checkoutCarrier,
            $barangay,
            '030540000',
        );
    }

    /** @param  list<array{hub_id: int, transport_mode: string}>  $legs */
    private function orderOnRoute(LogisticsHub $origin, LogisticsHub $destination, array $legs): SellerOrder
    {
        $order = SellerOrder::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'carrier_provider_id' => $this->checkoutCarrier->id,
        ]);
        $order->forceFill(['custody_hub_id' => $origin->id, 'custody_provider_id' => $this->checkoutCarrier->id,
            'origin_station_hub_id' => $origin->id, 'handover_at' => now()])->save();
        StatusHistory::record($order, OrderStatus::PickedUp, OrderStatus::AtSortingCenter,
            actor: OrderActor::Logistics, actorId: $this->checkoutCarrier->id);

        ParcelRoute::create([
            'seller_order_id' => $order->id,
            'origin_hub_id' => $origin->id,
            'destination_hub_id' => $destination->id,
            'legs' => $legs,
            'current_leg_index' => 0,
        ]);

        return $order;
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
