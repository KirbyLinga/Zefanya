<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use App\Services\CourierAssignmentService;
use App\Services\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FulfillmentLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    private LogisticsProvider $logisticsProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
            'municipality_name' => 'Quezon City',
        ]);

        $this->seller = Seller::factory()->create(['status' => 'approved']);

        $this->logisticsProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    public function test_complete_order_lifecycle_from_placed_to_completed(): void
    {
        // ── 1. Setup: product, order, seller_order at Placed ────────────────
        $product = Product::factory()->create([
            'seller_id' => $this->seller->id,
            'weight_grams' => 2000,
        ]);

        $order = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'shipping_address' => ['municipality' => 'Quezon City'],
        ]);

        $sellerOrder = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'status' => OrderStatus::Placed->value,
        ]);

        OrderItem::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $sellerOrder = $sellerOrder->fresh();
        $this->assertSame(OrderStatus::Placed, $sellerOrder->status);
        $this->assertSame(0, StatusHistory::where('seller_order_id', $sellerOrder->id)->count());

        // ── 2. Seller accepts order (Placed → Confirmed → Preparing) ────────
        $service = new SellerOrderStatusService;

        $sellerOrder = $service->transition($sellerOrder, OrderStatus::Confirmed, note: 'Order accepted.', changedBy: 'seller');
        $this->assertSame(OrderStatus::Confirmed, $sellerOrder->status);

        $sellerOrder = $service->transition($sellerOrder, OrderStatus::Preparing, note: 'Preparing for shipment.', changedBy: 'seller');
        $this->assertSame(OrderStatus::Preparing, $sellerOrder->status);

        // ── 3. Seller marks ready (Preparing → ReadyForPickup, NO auto-courier) ─
        $sellerOrder = $service->transition($sellerOrder, OrderStatus::ReadyForPickup, note: 'Packed by seller.', changedBy: 'seller');
        $this->assertSame(OrderStatus::ReadyForPickup, $sellerOrder->status);
        $this->assertNull($sellerOrder->courier_id);
        $this->assertSame(3, StatusHistory::where('seller_order_id', $sellerOrder->id)->count());

        // ── 4. Rider picks up (ReadyForPickup → PickedUp, assigns pickup courier) ─
        $pickupCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->logisticsProvider->id,
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'max_weight_grams' => 5000,
        ]);

        $assignment = new CourierAssignmentService($service);
        $assignedPickup = $assignment->assignPickup($sellerOrder);
        $this->assertNotNull($assignedPickup);
        $this->assertSame($pickupCourier->id, $assignedPickup->id);
        $this->assertFalse($assignedPickup->fresh()->is_available);

        $sellerOrder = $sellerOrder->fresh();
        $this->assertSame(OrderStatus::PickedUp, $sellerOrder->status);
        $this->assertSame($pickupCourier->id, $sellerOrder->courier_id);
        $this->assertNotNull($sellerOrder->tracking_number);
        $this->assertStringStartsWith('TRK-', $sellerOrder->tracking_number);
        $this->assertSame(4, StatusHistory::where('seller_order_id', $sellerOrder->id)->count());

        // ── 5. Rider hands to sorting center (PickedUp → AtSortingCenter) ─────
        $sellerOrder = $service->transition($sellerOrder, OrderStatus::AtSortingCenter, note: 'Handed to sorting center.');
        $this->assertSame(OrderStatus::AtSortingCenter, $sellerOrder->status);

        // Pickup courier is released
        $this->assertTrue($pickupCourier->fresh()->is_available);
        $this->assertSame(5, StatusHistory::where('seller_order_id', $sellerOrder->id)->count());

        // ── 6. Sorting center sorts (AtSortingCenter → Sorted) ───────────────
        $sellerOrder = $service->transition($sellerOrder, OrderStatus::Sorted, note: 'Sorted for delivery.');
        $this->assertSame(OrderStatus::Sorted, $sellerOrder->status);
        $this->assertSame(6, StatusHistory::where('seller_order_id', $sellerOrder->id)->count());

        // ── 7. Sorting center assigns delivery courier (Sorted → AssignedToRider) ─
        $deliveryCourier = Courier::factory()->create([
            'logistics_provider_id' => $this->logisticsProvider->id,
            'is_available' => true,
            'service_area' => ['Quezon City'],
            'vehicle_type' => 'motorcycle',
            'max_weight_grams' => 3000,
        ]);

        $assignedDelivery = $assignment->assignDelivery($sellerOrder);
        $this->assertNotNull($assignedDelivery);
        $this->assertSame($deliveryCourier->id, $assignedDelivery->id);
        $this->assertFalse($deliveryCourier->fresh()->is_available);

        $sellerOrder = $sellerOrder->fresh();
        $this->assertSame(OrderStatus::AssignedToRider, $sellerOrder->status);
        $this->assertSame($deliveryCourier->id, $sellerOrder->delivery_courier_id);
        $this->assertSame(7, StatusHistory::where('seller_order_id', $sellerOrder->id)->count());

        // Notification: Courier assigned (delivery)
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Buyer::class,
            'notifiable_id' => $this->buyer->id,
            'type' => 'App\\Notifications\\Buyer\\CourierAssigned',
        ]);

        // ── 8. Rider starts delivery (AssignedToRider → OutForDelivery) ───────
        $sellerOrder = $service->transition($sellerOrder, OrderStatus::OutForDelivery, note: 'Out for delivery.');
        $this->assertSame(OrderStatus::OutForDelivery, $sellerOrder->status);
        $this->assertSame(8, StatusHistory::where('seller_order_id', $sellerOrder->id)->count());

        // Notification: Out for delivery
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Buyer::class,
            'notifiable_id' => $this->buyer->id,
            'type' => 'App\\Notifications\\Buyer\\OrderOutForDelivery',
        ]);

        // ── 9. Rider delivers (OutForDelivery → Delivered) ────────────────────
        $sellerOrder = $service->transition($sellerOrder, OrderStatus::Delivered, note: 'Parcel delivered to buyer.');
        $this->assertSame(OrderStatus::Delivered, $sellerOrder->status);

        // Delivery courier is released
        $this->assertTrue($deliveryCourier->fresh()->is_available);
        $this->assertSame(9, StatusHistory::where('seller_order_id', $sellerOrder->id)->count());

        // Notification: Delivered
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Buyer::class,
            'notifiable_id' => $this->buyer->id,
            'type' => 'App\\Notifications\\Buyer\\OrderDelivered',
        ]);

        // ── 9.5. COD payment pending (settled only on buyer confirmation) ───────
        $codPayment = Payment::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'amount_minor' => $sellerOrder->subtotal_minor,
        ]);
        $this->assertSame(PaymentStatus::Pending, $codPayment->status);

        // ── 10. Buyer confirms receipt (Delivered → Completed) ────────────────
        $sellerOrder = $service->transition($sellerOrder, OrderStatus::Completed, note: 'Confirmed received by buyer.', changedBy: $this->buyer->fullName());
        $sellerOrder = $sellerOrder->fresh();
        $this->assertSame(OrderStatus::Completed, $sellerOrder->status);
        $this->assertSame(10, StatusHistory::where('seller_order_id', $sellerOrder->id)->count());

        // COD payment settled on confirmation.
        $this->assertSame(PaymentStatus::Paid, $codPayment->fresh()->status);
        $this->assertNotNull($codPayment->fresh()->paid_at);

        // Parent checkout-order status should have rolled up to Completed
        // (single seller_order, now Completed → parent rolls up).
        $this->assertSame(OrderStatus::Completed, $sellerOrder->order->status);

        // ── 11. Verify full status history chain ──────────────────────────────
        $historyRecords = StatusHistory::where('seller_order_id', $sellerOrder->id)
            ->orderBy('created_at')
            ->get();

        $history = [];
        foreach ($historyRecords as $record) {
            $history[$record->to_status->value] = $record->from_status->value;
        }

        $expected = [
            OrderStatus::Confirmed->value => OrderStatus::Placed->value,
            OrderStatus::Preparing->value => OrderStatus::Confirmed->value,
            OrderStatus::ReadyForPickup->value => OrderStatus::Preparing->value,
            OrderStatus::PickedUp->value => OrderStatus::ReadyForPickup->value,
            OrderStatus::AtSortingCenter->value => OrderStatus::PickedUp->value,
            OrderStatus::Sorted->value => OrderStatus::AtSortingCenter->value,
            OrderStatus::AssignedToRider->value => OrderStatus::Sorted->value,
            OrderStatus::OutForDelivery->value => OrderStatus::AssignedToRider->value,
            OrderStatus::Delivered->value => OrderStatus::OutForDelivery->value,
            OrderStatus::Completed->value => OrderStatus::Delivered->value,
        ];

        foreach ($expected as $from => $to) {
            $this->assertArrayHasKey($from, $history);
            $this->assertSame($to, $history[$from]);
        }
    }
}
