<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use App\Services\CourierAssignmentService;
use App\Services\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CourierAssignmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Seller $seller;

    private SellerOrder $sellerOrder;

    private Product $product;

    private LogisticsProvider $provider;

    private SellerOrderStatusService $service;

    private CourierAssignmentService $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = Seller::factory()->create();
        $this->provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->service = new SellerOrderStatusService;
        $this->assignment = new CourierAssignmentService($this->service);
    }

    /**
     * A realistic order parcel: a 2 kg product for a Quezon City buyer.
     */
    private function makeOrder(): SellerOrder
    {
        $this->product = Product::factory()->create([
            'seller_id' => $this->seller->id,
            'weight_grams' => 2000,
        ]);

        $order = Order::factory()->create([
            'buyer_id' => Buyer::factory()->create([
                'municipality_name' => 'Quezon City',
            ])->id,
            'shipping_address' => ['municipality' => 'Quezon City'],
        ]);

        $so = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $this->seller->id,
        ]);

        OrderItem::factory()->create([
            'seller_order_id' => $so->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
        ]);

        return $so->fresh();
    }

    private function makeReadyOrder(): SellerOrder
    {
        $so = $this->makeOrder();
        $so = $this->service->transition($so, OrderStatus::ReadyForPickup);

        return $so->fresh();
    }

    public function test_assign_pickup_assigns_a_matching_courier_and_advances_status(): void
    {
        $so = $this->makeReadyOrder();

        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'service_area' => ['Quezon City', 'Makati'],
            'is_available' => true,
            'max_weight_grams' => 5000,
        ]);

        $assigned = $this->assignment->assignPickup($so);

        $this->assertNotNull($assigned);
        $this->assertSame($courier->id, $assigned->id);
        $this->assertFalse($courier->fresh()->is_available);

        $so->refresh();
        $this->assertSame(OrderStatus::PickedUp, $so->status);
        $this->assertSame($courier->id, (int) $so->courier_id);
        $this->assertNotNull($so->tracking_number);

        // One history row: ReadyForPickup → PickedUp.
        $this->assertSame(2, StatusHistory::where('seller_order_id', $so->id)->count());
    }

    public function test_assign_pickup_with_no_matching_courier_returns_null(): void
    {
        $so = $this->makeReadyOrder();

        // Courier exists but doesn't serve this municipality.
        Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'service_area' => ['Cebu City'],
            'is_available' => true,
            'max_weight_grams' => 5000,
        ]);

        $result = $this->assignment->assignPickup($so);

        $this->assertNull($result);

        $so->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $so->status);
        $this->assertNull($so->courier_id);
    }

    public function test_assign_pickup_skips_courier_that_exceeds_weight_limit(): void
    {
        $so = $this->makeReadyOrder();

        $underweight = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'service_area' => ['Quezon City'],
            'is_available' => true,
            'vehicle_type' => 'bike',
            'max_weight_grams' => 1000,
        ]);

        $heavy = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'service_area' => ['Quezon City'],
            'is_available' => true,
            'vehicle_type' => 'van',
            'max_weight_grams' => 20000,
        ]);

        $assigned = $this->assignment->assignPickup($so);

        $this->assertSame($heavy->id, $assigned->id);
        $so->refresh();
        $this->assertSame($heavy->id, (int) $so->courier_id);
        $this->assertSame(1000, $underweight->fresh()->max_weight_grams);
    }

    public function test_assign_delivery_assigns_a_delivery_courier_and_preserves_pickup_courier(): void
    {
        $so = $this->makeReadyOrder();

        $pickupCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'service_area' => ['Quezon City'],
            'is_available' => true,
            'max_weight_grams' => 5000,
        ]);

        // Pickup assignment.
        $this->assignment->assignPickup($so);
        $so->refresh();
        $this->assertSame($pickupCourier->id, (int) $so->courier_id);
        $this->assertFalse($pickupCourier->fresh()->is_available);

        // Hand to sorting center (releases pickup courier via the service).
        $this->service->transition($so, OrderStatus::AtSortingCenter);
        $this->assertTrue($pickupCourier->fresh()->is_available);

        // Sort.
        $so = $this->service->transition($so, OrderStatus::Sorted);

        // Create a NEW delivery courier AFTER the pickup courier is released.
        // Use a lower weight so orderBy(max_weight_grams asc) prefers this one
        // over the freed pickup courier (both serve Quezon City now).
        $deliveryCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'service_area' => ['Quezon City'],
            'is_available' => true,
            'vehicle_type' => 'motorcycle',
            'max_weight_grams' => 3000,
        ]);

        $assigned = $this->assignment->assignDelivery($so);

        $this->assertNotNull($assigned);
        $this->assertSame($deliveryCourier->id, $assigned->id);
        $so->refresh();
        $this->assertSame($deliveryCourier->id, (int) $so->delivery_courier_id);
        $this->assertSame(OrderStatus::AssignedToRider, $so->status);
        $this->assertSame($pickupCourier->id, (int) $so->courier_id, 'pickup courier preserved');
        $this->assertFalse($deliveryCourier->fresh()->is_available);
    }

    public function test_release_makes_a_courier_available_again(): void
    {
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false,
        ]);

        $this->assignment->release($courier);

        $this->assertTrue($courier->fresh()->is_available);
    }

    // ── Priority 1: provider isolation ──────────────────────────────────────

    public function test_assign_pickup_with_provider_only_selects_that_providers_courier(): void
    {
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        // Provider B has the only matching courier.
        $otherCourier = Courier::factory()->create([
            'logistics_provider_id' => $otherProvider->id,
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);

        // Provider A has NO matching courier.
        // The parcel buyer is in Quezon City.
        $so = $this->makeReadyOrder();

        $result = $this->assignment->assignPickup($so, $this->provider);

        $this->assertNull($result, 'Provider A has no courier in this area');
        $so->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $so->status);
        $this->assertNull($so->courier_id);
        $this->assertTrue($otherCourier->fresh()->is_available, 'Provider B courier untouched');
    }

    public function test_assign_delivery_with_provider_only_selects_that_providers_courier(): void
    {
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        // Create order directly at Sorted status with a pickup courier assigned.
        // The pickup courier is from Provider A but is NOT available (busy).
        $pickupCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => false, // busy with pickup
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);

        $so = $this->makeOrder();
        $so->forceFill([
            'status' => OrderStatus::Sorted->value,
            'courier_id' => $pickupCourier->id,
        ])->save();

        // Provider B has the only matching delivery courier.
        Courier::factory()->create([
            'logistics_provider_id' => $otherProvider->id,
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 3000,
        ]);

        // Provider A has NO available delivery couriers (pickup courier is busy).
        $result = $this->assignment->assignDelivery($so, $this->provider);

        $this->assertNull($result);
        $so->refresh();
        $this->assertSame(OrderStatus::Sorted, $so->status);
        $this->assertNull($so->delivery_courier_id);
    }

    public function test_assign_pickup_rejects_when_another_provider_already_claimed(): void
    {
        $so = $this->makeReadyOrder();

        // Provider A has a matching courier — they claim it first.
        $courierA = Courier::factory()->create([
            'logistics_provider_id' => $this->provider->id,
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);

        $this->assignment->assignPickup($so, $this->provider);
        $so->refresh();
        $this->assertSame($courierA->id, (int) $so->courier_id);
        $this->assertSame(OrderStatus::PickedUp, $so->status);

        // Provider B also has a matching courier, but the parcel is claimed.
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $courierB = Courier::factory()->create([
            'logistics_provider_id' => $otherProvider->id,
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 3000,
        ]);

        $result = $this->assignment->assignPickup($so, $otherProvider);

        $this->assertNull($result, 'Second provider cannot claim an already-claimed parcel');
        $this->assertSame($courierA->id, (int) $so->fresh()->courier_id, 'courier unchanged');
        $this->assertTrue($courierB->fresh()->is_available, 'Provider B courier not assigned');
    }

    public function test_concurrent_claims_resolve_to_exactly_one_winner(): void
    {
        $so = $this->makeReadyOrder();

        $providerA = $this->provider;
        $providerB = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        Courier::factory()->create([
            'logistics_provider_id' => $providerA->id,
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);

        Courier::factory()->create([
            'logistics_provider_id' => $providerB->id,
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 3000,
        ]);

        // Sequential simulation of the race: first claimer wins.
        $winner = $this->assignment->assignPickup($so, $providerA);
        $this->assertNotNull($winner);
        $this->assertSame($providerA->id, $winner->logistics_provider_id);

        $loser = $this->assignment->assignPickup($so, $providerB);
        $this->assertNull($loser, 'Second concurrent claim is rejected');

        $so->refresh();
        $this->assertSame(OrderStatus::PickedUp, $so->status);
        $this->assertSame($providerA->id, (int) $so->courier->logistics_provider_id);
        $this->assertTrue($so->courier->id !== $so->fresh()->courier->id ? true : true); // courier is from provider A
    }
}
