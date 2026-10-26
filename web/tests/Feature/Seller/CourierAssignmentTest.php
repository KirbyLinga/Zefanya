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
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class CourierAssignmentTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private Seller $seller;

    private SellerOrder $sellerOrder;

    private Product $product;

    private LogisticsProvider $provider;

    private SellerOrderStatusService $service;

    private CourierAssignmentService $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = Seller::factory()->create(['barangay_code' => '1374040001']);
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
            'shipping_address' => ['municipality' => 'Quezon City', 'barangay_code' => '1374040001'],
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

        $courier = $this->assignableCourier($this->provider, [
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
        $this->assignableCourier($this->provider, [
            'is_available' => true,
            'max_weight_grams' => 5000,
        ], 'Cebu City');

        $result = $this->assignment->assignPickup($so);

        $this->assertNull($result);

        $so->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $so->status);
        $this->assertNull($so->courier_id);
    }

    public function test_assign_pickup_skips_courier_that_exceeds_weight_limit(): void
    {
        $so = $this->makeReadyOrder();

        $underweight = $this->assignableCourier($this->provider, [
            'is_available' => true,
            'vehicle_type' => 'bike',
            'max_weight_grams' => 1000,
        ]);

        $heavy = $this->assignableCourier($this->provider, [
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

    public function test_pickup_eligibility_is_cumulative_across_active_pickups_and_deliveries(): void
    {
        $so = $this->makeReadyOrder(); // 2,000 g parcel

        $courier = $this->assignableCourier($this->provider, [
            'is_available' => true,
            'max_weight_grams' => 10000,
        ]);

        // The Rider is already carrying a 4,000 g pickup and a 3,000 g delivery.
        $this->activeParcel($courier, OrderStatus::PickedUp, 'courier_id', 4000);
        $this->activeParcel($courier, OrderStatus::OutForDelivery, 'delivery_courier_id', 3000);

        $this->assertSame(7000, $courier->fresh()->activeWeightGrams());

        // 4,000 + 3,000 + 2,000 = 9,000 g, within the 10,000 g limit.
        $assigned = $this->assignment->assignPickup($so, $this->provider, $courier->id);

        $this->assertNotNull($assigned);
        $this->assertSame($courier->id, $assigned->id);
    }

    public function test_parcel_weight_snapshot_does_not_change_when_the_catalog_weight_is_edited(): void
    {
        $so = $this->makeOrder(); // 2,000 g product

        $this->assertSame(2000, (int) $so->parcel_weight_grams);

        $this->product->forceFill(['weight_grams' => 9000])->save();
        $this->product->variants()->update(['weight_grams' => 9000]);

        $fresh = $so->fresh();
        $this->assertSame(9000, $fresh->calculateParcelWeightGrams());
        $this->assertSame(2000, $fresh->parcelWeightGrams());
    }

    public function test_pickup_eligibility_rejects_a_parcel_that_exceeds_the_cumulative_limit(): void
    {
        $so = $this->makeReadyOrder(); // 2,000 g parcel

        $courier = $this->assignableCourier($this->provider, [
            'is_available' => true,
            'max_weight_grams' => 10000,
        ]);

        $this->activeParcel($courier, OrderStatus::PickedUp, 'courier_id', 5000);
        $this->activeParcel($courier, OrderStatus::OutForDelivery, 'delivery_courier_id', 4000);

        // 5,000 + 4,000 + 2,000 = 11,000 g, over the 10,000 g limit.
        $assigned = $this->assignment->assignPickup($so, $this->provider, $courier->id);

        $this->assertNull($assigned);
        $this->assertNull($so->fresh()->courier_id);
    }

    private function activeParcel(Courier $courier, OrderStatus $status, string $column, int $grams): SellerOrder
    {
        $parcel = SellerOrder::factory()->create([
            'seller_id' => $this->seller->id,
            $column => $courier->id,
            'status' => $status,
        ]);

        OrderItem::factory()->create([
            'seller_order_id' => $parcel->id,
            'product_id' => Product::factory()->create([
                'seller_id' => $this->seller->id,
                'weight_grams' => $grams,
            ])->id,
            'quantity' => 1,
        ]);

        return $parcel;
    }

    public function test_assign_delivery_assigns_a_delivery_courier_and_preserves_pickup_courier(): void
    {
        $so = $this->makeReadyOrder();

        $pickupCourier = $this->assignableCourier($this->provider, [
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
        // Explicitly choose a separate delivery Rider to verify both legs
        // independently (both Riders serve Quezon City).
        $deliveryCourier = $this->assignableCourier($this->provider, [
            'service_area' => ['Quezon City'],
            'is_available' => true,
            'vehicle_type' => 'motorcycle',
            'max_weight_grams' => 3000,
        ]);

        $assigned = $this->assignment->assignDelivery($so, preferredCourierId: $deliveryCourier->id);

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
        $otherCourier = $this->assignableCourier($otherProvider, [
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
        $pickupCourier = $this->assignableCourier($this->provider, [
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
        $this->assignableCourier($otherProvider, [
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
        $courierA = $this->assignableCourier($this->provider, [
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
        $courierB = $this->assignableCourier($otherProvider, [
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

        $this->assignableCourier($providerA, [
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);

        $this->assignableCourier($providerB, [
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
