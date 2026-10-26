<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
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
use App\Services\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BuyerOrderLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
            'municipality_name' => 'Quezon City',
        ]);
    }

    private function fullOrder(): Order
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'weight_grams' => 2000,
        ]);

        $order = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'shipping_address' => [
                'municipality' => 'Quezon City',
            ],
        ]);

        $so = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'status' => OrderStatus::Placed->value,
        ]);

        OrderItem::factory()->create([
            'seller_order_id' => $so->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        return $order->fresh(['sellerOrders.orderItems.product', 'sellerOrders.courier']);
    }

    public function test_buyer_can_see_their_order_in_the_index(): void
    {
        $order = $this->fullOrder();

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index'))
            ->assertOk()
            ->assertSee($order->reference);
    }

    public function test_buyer_can_see_order_detail_with_timeline_and_tracking(): void
    {
        $order = $this->fullOrder();

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee($order->reference)
            ->assertSee('Placed');
    }

    public function test_buyer_cannot_see_other_buyers_orders(): void
    {
        $other = Buyer::factory()->create(['status' => 'approved']);
        $otherOrder = Order::factory()->create(['buyer_id' => $other->id]);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index'))
            ->assertOk()
            ->assertDontSee($otherOrder->reference);
    }

    public function test_index_renders_reference_design_shell_with_real_buyer_info(): void
    {
        $order = $this->fullOrder();

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index'))
            ->assertOk()
            ->assertSeeText('Orders & Tracking')
            ->assertSee($this->buyer->fullName())
            ->assertSee($this->buyer->email)
            ->assertSeeText('Orders & History')
            ->assertSee('Shipping Addresses')
            ->assertSee('Track deliveries, review orders, and request returns')
            ->assertSee('View Details')
            // Fake loyalty figures from the mockup must never render — Zefanya
            // has no rewards/points/store-credit columns.
            ->assertDontSee('pts')
            ->assertDontSee('Store Credit');
    }

    public function test_index_tab_filter_only_returns_matching_orders(): void
    {
        $shippable = $this->fullOrder();

        // A second order already completed by its seller_order.
        $completedOrder = Order::factory()->create(['buyer_id' => $this->buyer->id]);
        $so = SellerOrder::factory()->create([
            'order_id' => $completedOrder->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'status' => OrderStatus::Completed->value,
        ]);
        OrderItem::factory()->create(['seller_order_id' => $so->id, 'quantity' => 1]);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index', ['tab' => 'completed']))
            ->assertOk()
            ->assertSee($completedOrder->reference)
            ->assertDontSee($shippable->reference);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index', ['tab' => 'to_ship']))
            ->assertOk()
            ->assertSee($shippable->reference)
            ->assertDontSee($completedOrder->reference);
    }

    public function test_index_search_matches_reference_and_product_name(): void
    {
        $order = $this->fullOrder();

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index', ['search' => $order->reference]))
            ->assertOk()
            ->assertSee($order->reference);

        $productName = $order->sellerOrders->first()->orderItems->first()->product_name;

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index', ['search' => $productName]))
            ->assertOk()
            ->assertSee($order->reference);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index', ['search' => 'no-such-thing-xyz']))
            ->assertOk()
            ->assertSee('No orders found');
    }

    /**
     * Build a two-seller order whose slices start at the given statuses.
     * The order deliberately has TWO seller_orders so multi-seller
     * confirm-received behaviour can be exercised.
     *
     * @return array{0: Order, 1: SellerOrder, 2: SellerOrder}
     */
    private function twoSellerOrder(OrderStatus $statusA, OrderStatus $statusB): array
    {
        $order = Order::factory()->create(['buyer_id' => $this->buyer->id]);

        $sliceA = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'status' => $statusA->value,
        ]);

        $sliceB = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'status' => $statusB->value,
        ]);

        OrderItem::factory()->create(['seller_order_id' => $sliceA->id, 'quantity' => 1]);
        OrderItem::factory()->create(['seller_order_id' => $sliceB->id, 'quantity' => 1]);

        return [$order->fresh(), $sliceA, $sliceB];
    }

    public function test_confirm_received_is_offered_when_one_slice_is_completed_and_another_delivered(): void
    {
        [$order, $sliceA, $sliceB] = $this->twoSellerOrder(
            OrderStatus::Completed,
            OrderStatus::Delivered,
        );

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Confirm this shipment received')
            ->assertSee('name="seller_order_id" value="'.$sliceB->id.'"', false)
            ->assertDontSee('name="seller_order_id" value="'.$sliceA->id.'"', false);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.orders.confirmReceived', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        // Only the Delivered slice moves; the Completed one is untouched.
        $this->assertSame(OrderStatus::Completed, $sliceB->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $sliceA->fresh()->status);
    }

    public function test_confirm_received_is_hidden_when_every_slice_is_completed(): void
    {
        [$order] = $this->twoSellerOrder(OrderStatus::Completed, OrderStatus::Completed);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertDontSee('Confirm this shipment received')
            ->assertDontSee('name="seller_order_id"', false);
    }

    public function test_confirm_received_is_hidden_when_no_slice_is_delivered(): void
    {
        [$order] = $this->twoSellerOrder(
            OrderStatus::OutForDelivery,
            OrderStatus::ReadyForPickup,
        );

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertDontSee('Confirm this shipment received')
            ->assertDontSee('name="seller_order_id"', false);
    }

    public function test_confirm_received_transitions_delivered_to_completed_and_fires_notification(): void
    {
        $order = $this->fullOrder();
        $so = $order->sellerOrders->first();

        // Drive the order through the full courier pipeline to Delivered.
        // New canonical flow: ReadyForPickup → PickedUp → AtSortingCenter → Sorted → AssignedToRider → OutForDelivery → Delivered
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $courier = Courier::factory()->create([
            'logistics_provider_id' => $provider->id,
            'is_available' => false,
        ]);

        $so->forceFill(['courier_id' => $courier->id, 'status' => OrderStatus::ReadyForPickup->value])->save();

        $service = new SellerOrderStatusService;
        // Walk the full pipeline with correct actors:
        $so = $service->transition($so, OrderStatus::PickedUp, actor: OrderActor::Rider, note: 'sim');
        $so = $service->transition($so, OrderStatus::AtSortingCenter, actor: OrderActor::Logistics, note: 'sim');
        $so = $service->transition($so, OrderStatus::Sorted, actor: OrderActor::Logistics, note: 'sim');
        $so = $service->transition($so, OrderStatus::AssignedToRider, actor: OrderActor::Logistics, note: 'sim');
        $so = $service->transition($so, OrderStatus::OutForDelivery, actor: OrderActor::Rider, note: 'sim');
        $so = $service->transition($so, OrderStatus::Delivered, actor: OrderActor::Rider, note: 'sim');

        $so->refresh();
        $this->assertSame(OrderStatus::Delivered, $so->status);

        // Buyer confirms receipt.
        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.orders.confirmReceived', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $so->refresh();
        $this->assertSame(OrderStatus::Completed, $so->status);

        // Notification was stored.
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Buyer::class,
            'notifiable_id' => $this->buyer->id,
            'type' => 'App\\Notifications\\Buyer\\OrderDelivered',
        ]);
    }

    // ── Parent order rollup ──────────────────────────────────────────────

    public function test_parent_order_rolls_up_to_completed_when_all_seller_orders_confirm_received(): void
    {
        [$order, $sliceA, $sliceB] = $this->twoSellerOrder(
            OrderStatus::Delivered,
            OrderStatus::Delivered,
        );

        // Parent order should NOT be Completed while slices are still Delivered.
        $this->assertNotSame(OrderStatus::Completed, $order->fresh()->status);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.orders.confirmReceived', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        // Both slices moved to Completed.
        $this->assertSame(OrderStatus::Completed, $sliceA->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $sliceB->fresh()->status);

        // Parent order rolled up from Completed slices.
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_parent_order_status_reflects_bottleneck_when_only_one_of_two_completes(): void
    {
        [$order, $sliceA, $sliceB] = $this->twoSellerOrder(
            OrderStatus::Delivered,
            OrderStatus::Delivered,
        );

        $service = new SellerOrderStatusService;

        // Confirm receipt for only one slice.
        $service->transition(
            $sliceA,
            OrderStatus::Completed,
            note: 'Confirmed received by buyer.',
            changedBy: $this->buyer->fullName(),
            actor: OrderActor::Buyer,
            actorId: $this->buyer->id,
            source: 'web',
        );

        $this->assertSame(OrderStatus::Completed, $sliceA->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $sliceB->fresh()->status);

        // Parent must reflect the bottleneck (Delivered), not Completed.
        $parentStatus = $order->fresh()->status;
        $this->assertSame(OrderStatus::Delivered, $parentStatus);
        $this->assertNotSame(OrderStatus::Completed, $parentStatus);
    }

    // ── COD settlement ───────────────────────────────────────────────────

    public function test_cod_payment_is_settled_when_buyer_confirms_received(): void
    {
        $order = $this->fullOrder();
        $so = $order->sellerOrders->first();

        // Attach a pending COD payment for this seller_order.
        $payment = Payment::factory()->create([
            'seller_order_id' => $so->id,
            'amount_minor' => $so->subtotal_minor,
        ]);

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNull($payment->paid_at);

        // Set the seller_order to Delivered (bypass the full pipeline —
        // we are testing the COD-settlement-on-confirm path, not the courier flow).
        $so->forceFill(['status' => OrderStatus::Delivered->value])->save();

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.orders.confirmReceived', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $payment = $payment->fresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_cod_payment_not_re_settled_when_already_paid(): void
    {
        $order = $this->fullOrder();
        $so = $order->sellerOrders->first();

        // Attach an already-paid payment.
        $paidAt = now()->subHour();
        $payment = Payment::factory()->create([
            'seller_order_id' => $so->id,
            'amount_minor' => $so->subtotal_minor,
            'status' => PaymentStatus::Paid->value,
            'paid_at' => $paidAt,
        ]);

        // Drive the seller_order to Delivered, then transition to Completed.
        $so->forceFill(['status' => OrderStatus::Delivered->value])->save();

        $service = new SellerOrderStatusService;
        $service->transition(
            $so->fresh(),
            OrderStatus::Completed,
            note: 'Confirmed received by buyer.',
            actor: OrderActor::Buyer,
            actorId: $this->buyer->id,
            source: 'web',
        );

        // Payment was already Paid — status and paid_at must not change.
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertEqualsWithDelta($paidAt->timestamp, $payment->fresh()->paid_at->timestamp, 1, 'paid_at unchanged');
    }

    public function test_cod_payment_not_settled_for_non_cod_method_if_any(): void
    {
        // The only PaymentMethod is Cod; this test documents that the service
        // only settles payments that are currently Pending (guard against
        // future payment methods that may have different settlement rules).
        $order = $this->fullOrder();
        $so = $order->sellerOrders->first();

        $payment = Payment::factory()->create([
            'seller_order_id' => $so->id,
            'amount_minor' => $so->subtotal_minor,
            'method' => PaymentMethod::Cod->value,
            'status' => PaymentStatus::Pending->value,
        ]);

        $so->forceFill(['status' => OrderStatus::Delivered->value])->save();

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.orders.confirmReceived', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    // ── Two-seller rollup (OutForDelivery + Delivered) ───────────────────

    public function test_two_seller_rollup_delivered_plus_out_for_delivery_then_both_delivered(): void
    {
        $order = Order::factory()->create(['buyer_id' => $this->buyer->id]);

        $soA = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->getKey(),
            'status' => OrderStatus::Delivered->value,
        ]);

        $soB = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->getKey(),
            'status' => OrderStatus::OutForDelivery->value,
        ]);

        OrderItem::factory()->create(['seller_order_id' => $soA->id, 'quantity' => 1]);
        OrderItem::factory()->create(['seller_order_id' => $soB->id, 'quantity' => 1]);

        // A=Delivered (rank 9), B=OutForDelivery (rank 8) → bottleneck = OutForDelivery.
        $order = $order->fresh()->load('sellerOrders');
        $order->rollUpStatus();
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);

        // Move B to Delivered → both Delivered.
        $soB->forceFill(['status' => OrderStatus::Delivered->value])->save();
        $order = $order->fresh()->load('sellerOrders');
        $order->rollUpStatus();
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    // ── Mixed Completed + Delivered: confirm + settle all payments ──────

    public function test_mixed_completed_and_delivered_confirms_and_settles_all_payments(): void
    {
        $order = Order::factory()->create(['buyer_id' => $this->buyer->id]);

        $soA = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->getKey(),
            'status' => OrderStatus::Completed->value,
        ]);

        $soB = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->getKey(),
            'status' => OrderStatus::Delivered->value,
        ]);

        Payment::factory()->create([
            'seller_order_id' => $soA->id,
            'amount_minor' => $soA->subtotal_minor,
            'status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ]);

        $paymentB = Payment::factory()->create([
            'seller_order_id' => $soB->id,
            'amount_minor' => $soB->subtotal_minor,
        ]);

        $this->assertTrue($order->fresh()->load('sellerOrders')->canBeConfirmed());
        $this->assertSame(PaymentStatus::Pending, $paymentB->fresh()->status);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.orders.confirmReceived', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::Completed, $soA->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $soB->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $paymentB->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $soA->payment->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    // ── Confirm gate: rejected when a slice is still in transit ────────

    public function test_confirm_received_completes_delivered_slice_while_sibling_is_in_transit(): void
    {
        [$order, $sliceA, $sliceB] = $this->twoSellerOrder(
            OrderStatus::OutForDelivery,
            OrderStatus::Delivered,
        );

        $this->assertTrue($order->fresh()->load('sellerOrders')->canBeConfirmed());

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.orders.confirmReceived', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        // The in-transit sibling is never advanced.
        $this->assertSame(OrderStatus::OutForDelivery, $sliceA->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $sliceB->fresh()->status);
    }

    // ── COD settlement on Delivered (cash-at-door) ──────────────────────

    public function test_confirm_received_rejects_selected_in_transit_shipment_without_changing_delivered_sibling(): void
    {
        [$order, $inTransit, $delivered] = $this->twoSellerOrder(
            OrderStatus::OutForDelivery,
            OrderStatus::Delivered,
        );
        $payments = Payment::factory()->createMany([
            ['seller_order_id' => $inTransit->id],
            ['seller_order_id' => $delivered->id],
        ]);
        $beforePayments = $payments->map(fn ($payment) => $payment->fresh()->getAttributes())->all();
        $historyCount = DB::table('status_histories')->count();

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.orders.confirmReceived', $order), ['seller_order_id' => $inTransit->id])
            ->assertRedirect()
            ->assertSessionHasErrors('shipment');

        $this->assertSame(OrderStatus::OutForDelivery, $inTransit->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $delivered->fresh()->status);
        $this->assertSame($beforePayments, $payments->map(fn ($payment) => $payment->fresh()->getAttributes())->all());
        $this->assertDatabaseCount('status_histories', $historyCount);
    }

    public function test_cod_payment_settled_on_delivered_transition(): void
    {
        $order = $this->fullOrder();
        $so = $order->sellerOrders->first();

        $payment = Payment::factory()->create([
            'seller_order_id' => $so->id,
            'amount_minor' => $so->subtotal_minor,
        ]);

        $this->assertSame(PaymentStatus::Pending, $payment->status);

        // Drive to OutForDelivery, then transition to Delivered via the service.
        $so->forceFill(['status' => OrderStatus::OutForDelivery->value])->save();

        $service = new SellerOrderStatusService;
        $service->transition(
            $so->fresh(),
            OrderStatus::Delivered,
            note: 'Delivered to buyer.',
            actor: OrderActor::Rider,
            actorId: 1,
            source: 'web',
        );

        // COD settled at the door (Delivered), not at confirmation.
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
    }

    public function test_cod_settlement_on_delivered_not_changed_by_completed(): void
    {
        $order = $this->fullOrder();
        $so = $order->sellerOrders->first();

        $payment = Payment::factory()->create([
            'seller_order_id' => $so->id,
            'amount_minor' => $so->subtotal_minor,
        ]);

        // Delivered — settles COD.
        $so->forceFill(['status' => OrderStatus::OutForDelivery->value])->save();

        $service = new SellerOrderStatusService;
        $service->transition(
            $so->fresh(),
            OrderStatus::Delivered,
            note: 'Delivered.',
            actor: OrderActor::Rider,
        );

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $paidAt = $payment->paid_at;

        // Completed — must NOT overwrite paid_at.
        $service->transition(
            $so->fresh(),
            OrderStatus::Completed,
            note: 'Confirmed received.',
            actor: OrderActor::Buyer,
            actorId: $this->buyer->id,
            source: 'web',
        );

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertEqualsWithDelta($paidAt->timestamp, $payment->paid_at->timestamp, 1);
    }

    // ── Missing payment does not crash ───────────────────────────────────

    public function test_missing_payment_does_not_crash_on_delivered(): void
    {
        $order = $this->fullOrder();
        $so = $order->sellerOrders->first();

        // No payment row created.
        $so->forceFill(['status' => OrderStatus::OutForDelivery->value])->save();

        $service = new SellerOrderStatusService;

        $result = $service->transition(
            $so->fresh(),
            OrderStatus::Delivered,
            note: 'Delivered.',
            actor: OrderActor::Rider,
        );

        $this->assertSame(OrderStatus::Delivered, $result->status);
    }

    // ── Another buyer → 404 ────────────────────────────────────────────

    public function test_another_buyers_order_returns_404_on_confirm(): void
    {
        [$order, $sliceA, $sliceB] = $this->twoSellerOrder(
            OrderStatus::Delivered,
            OrderStatus::Delivered,
        );

        $otherBuyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($otherBuyer, 'buyer')
            ->get(route('buyer.orders.show', $order))
            ->assertNotFound();

        $this->actingAs($otherBuyer, 'buyer')
            ->post(route('buyer.orders.confirmReceived', $order))
            ->assertNotFound();

        $this->assertSame(OrderStatus::Delivered, $sliceA->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $sliceB->fresh()->status);
    }

    // ── Transaction rollback ─────────────────────────────────────────────

    public function test_rollback_when_cod_settlement_throws(): void
    {
        $order = $this->fullOrder();
        $so = $order->sellerOrders->first();

        $payment = Payment::factory()->create([
            'seller_order_id' => $so->id,
            'amount_minor' => $so->subtotal_minor,
        ]);

        $so->forceFill(['status' => OrderStatus::Delivered->value])->save();

        Event::listen('eloquent.saving: '.Payment::class, function () {
            throw new \RuntimeException('Simulated payment save failure');
        });

        $service = new SellerOrderStatusService;

        try {
            $service->transition(
                $so->fresh(),
                OrderStatus::Completed,
                note: 'Confirm.',
                actor: OrderActor::Buyer,
                actorId: $this->buyer->id,
                source: 'web',
            );
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated payment save failure', $e->getMessage());
        } finally {
            Event::forget('eloquent.saving: '.Payment::class);
        }

        // Rollback: seller_order stays Delivered, payment stays Pending.
        $this->assertSame(OrderStatus::Delivered, $so->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_rollback_when_rollup_throws(): void
    {
        $order = $this->fullOrder();
        $so = $order->sellerOrders->first();

        $so->forceFill(['status' => OrderStatus::Delivered->value])->save();

        Event::listen('eloquent.saving: '.Order::class, function () {
            throw new \RuntimeException('Simulated rollup failure');
        });

        $service = new SellerOrderStatusService;

        try {
            $service->transition(
                $so->fresh(),
                OrderStatus::Completed,
                note: 'Confirm.',
                actor: OrderActor::Buyer,
                actorId: $this->buyer->id,
                source: 'web',
            );
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated rollup failure', $e->getMessage());
        } finally {
            Event::forget('eloquent.saving: '.Order::class);
        }

        // Rollback: seller_order stays Delivered.
        $this->assertSame(OrderStatus::Delivered, $so->fresh()->status);
    }

    public function test_cancelled_tab_counts_and_lists_seller_declined_orders(): void
    {
        $order = $this->fullOrder();
        $order->sellerOrders->first()->forceFill(['status' => OrderStatus::Cancelled->value])->save();

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index', ['tab' => 'cancelled']))
            ->assertOk()
            ->assertSee($order->reference)
            ->assertSee('Cancelled', false);
    }

    public function test_mixed_cancelled_and_active_siblings_count_as_to_ship_not_cancelled(): void
    {
        $sellerA = Seller::factory()->create(['status' => 'approved']);
        $sellerB = Seller::factory()->create(['status' => 'approved']);

        $order = Order::factory()->create([
            'buyer_id' => $this->buyer->id,
            'shipping_address' => ['municipality' => 'Quezon City'],
        ]);

        SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $sellerA->id,
            'status' => OrderStatus::Cancelled->value,
        ]);
        SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $sellerB->id,
            'status' => OrderStatus::Placed->value,
        ]);

        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index'))
            ->assertOk()
            ->assertSee($order->reference);

        // Active PLACED sibling keeps the order in to_ship.
        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index', ['tab' => 'to_ship']))
            ->assertOk()
            ->assertSee($order->reference);

        // Cancelled sibling also makes it match the cancelled filter.
        $this->actingAs($this->buyer, 'buyer')
            ->get(route('buyer.orders.index', ['tab' => 'cancelled']))
            ->assertOk()
            ->assertSee($order->reference);
    }
}
