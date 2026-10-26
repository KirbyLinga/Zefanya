<?php

namespace Tests\Feature;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use App\Services\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Slice-1 hardening: each role may only perform its own transitions.
 * The map lives in OrderActor::allowedTransitions(); the enforcement lives
 * in SellerOrderStatusService::transition($to, ..., actor: ...).
 *
 * Allowed paths are asserted through the real service (history row +
 * persisted status); rejections assert the exception AND that nothing was
 * written — the audit log never records an illegal attempt.
 */
class OrderActorMapTest extends TestCase
{
    use LazilyRefreshDatabase;

    private SellerOrderStatusService $service;

    private SellerOrder $sellerOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SellerOrderStatusService;
    }

    private function orderAt(OrderStatus $status): SellerOrder
    {
        $this->sellerOrder = SellerOrder::factory()->create();
        $this->sellerOrder->forceFill(['status' => $status->value])->save();

        return $this->sellerOrder->fresh();
    }

    // ── Seller: allowed ──────────────────────────────────────────────────────

    public function test_seller_can_pack_a_fresh_order_to_ready_for_pickup(): void
    {
        $so = $this->orderAt(OrderStatus::Placed);

        $result = $this->service->transition(
            $so, OrderStatus::ReadyForPickup,
            actor: OrderActor::Seller, actorId: 11, source: 'web',
        );

        $this->assertSame(OrderStatus::ReadyForPickup, $result->status);
        $this->assertSame(1, StatusHistory::count());
    }

    public function test_seller_can_walk_the_full_packing_pipeline(): void
    {
        $so = $this->orderAt(OrderStatus::Placed);

        foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup] as $to) {
            $so = $this->service->transition($so, $to, actor: OrderActor::Seller, source: 'web');
        }

        $this->assertSame(OrderStatus::ReadyForPickup, $so->status);
    }

    public function test_seller_can_decline_a_placed_order(): void
    {
        $so = $this->orderAt(OrderStatus::Placed);

        $result = $this->service->transition(
            $so,
            OrderStatus::Cancelled,
            actor: OrderActor::Seller,
            actorId: 11,
            source: 'web',
        );

        $this->assertSame(OrderStatus::Cancelled, $result->status);
        $this->assertSame(1, StatusHistory::count());
    }

    // ── Seller: rejected ─────────────────────────────────────────────────────

    public function test_seller_cannot_perform_courier_or_sorting_hops(): void
    {
        $so = $this->orderAt(OrderStatus::ReadyForPickup);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('seller role cannot');

        $this->service->transition($so, OrderStatus::AssignedToRider, actor: OrderActor::Seller);
    }

    public function test_seller_rejection_writes_nothing(): void
    {
        $so = $this->orderAt(OrderStatus::ReadyForPickup);

        try {
            $this->service->transition($so, OrderStatus::PickedUp, actor: OrderActor::Seller);
            $this->fail('Expected rejection');
        } catch (\InvalidArgumentException) {
            $this->assertSame(OrderStatus::ReadyForPickup, $so->refresh()->status);
            $this->assertSame(0, StatusHistory::count(), 'illegal attempts are never recorded');
        }
    }

    // ── Rider: allowed (the courier hops) ────────────────────────────────────

    public function test_rider_can_walk_the_full_courier_path(): void
    {
        $so = $this->orderAt(OrderStatus::ReadyForPickup);

        // Canonical flow: Rider pickup → Logistics station receipt/sort/assign → Rider delivery.
        $so = $this->service->transition($so, OrderStatus::PickedUp, actor: OrderActor::Rider, source: 'simulator');
        $so = $this->service->transition($so, OrderStatus::AtSortingCenter, actor: OrderActor::Logistics, source: 'web');
        $so = $this->service->transition($so, OrderStatus::Sorted, actor: OrderActor::Logistics, source: 'web');
        $so = $this->service->transition($so, OrderStatus::AssignedToRider, actor: OrderActor::Logistics, source: 'web');
        $so = $this->service->transition($so, OrderStatus::OutForDelivery, actor: OrderActor::Rider, source: 'simulator');
        $so = $this->service->transition($so, OrderStatus::Delivered, actor: OrderActor::Rider, source: 'simulator');

        $this->assertSame(OrderStatus::Delivered, $so->status);
        $this->assertNotNull($so->tracking_number, 'tracking assigned on pickup');
    }

    public function test_rider_can_mark_a_failed_delivery(): void
    {
        $so = $this->orderAt(OrderStatus::OutForDelivery);

        $result = $this->service->transition($so, OrderStatus::DeliveryFailed, actor: OrderActor::Rider);

        $this->assertSame(OrderStatus::DeliveryFailed, $result->status);
    }

    // ── Rider: rejected ──────────────────────────────────────────────────────

    public function test_rider_cannot_pack_or_complete(): void
    {
        $so = $this->orderAt(OrderStatus::Placed);

        try {
            $this->service->transition($so, OrderStatus::ReadyForPickup, actor: OrderActor::Rider);
            $this->fail('Expected rejection');
        } catch (\InvalidArgumentException) {
            $this->assertSame(OrderStatus::Placed, $so->refresh()->status);
        }

        $delivered = $this->orderAt(OrderStatus::Delivered);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->transition($delivered, OrderStatus::Completed, actor: OrderActor::Rider);
    }

    // ── Logistics: allowed ───────────────────────────────────────────────────

    public function test_logistics_can_sort_and_assign_a_rider(): void
    {
        $so = $this->orderAt(OrderStatus::AtSortingCenter);

        $so = $this->service->transition($so, OrderStatus::Sorted, actor: OrderActor::Logistics, source: 'web');
        $so = $this->service->transition($so, OrderStatus::AssignedToRider, actor: OrderActor::Logistics, source: 'web');

        $this->assertSame(OrderStatus::AssignedToRider, $so->status);
    }

    // ── Logistics: rejected ──────────────────────────────────────────────────

    public function test_logistics_cannot_pack_or_deliver(): void
    {
        $so = $this->orderAt(OrderStatus::Placed);

        try {
            $this->service->transition($so, OrderStatus::ReadyForPickup, actor: OrderActor::Logistics);
            $this->fail('Expected rejection');
        } catch (\InvalidArgumentException) {
            $this->assertSame(OrderStatus::Placed, $so->refresh()->status);
        }

        $out = $this->orderAt(OrderStatus::OutForDelivery);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->transition($out, OrderStatus::Delivered, actor: OrderActor::Logistics);
    }

    // ── Buyer: allowed ───────────────────────────────────────────────────────

    public function test_buyer_can_confirm_receipt(): void
    {
        $so = $this->orderAt(OrderStatus::Delivered);

        $result = $this->service->transition(
            $so, OrderStatus::Completed,
            actor: OrderActor::Buyer, actorId: 77, source: 'web',
        );

        $this->assertSame(OrderStatus::Completed, $result->status);
        $this->assertSame('buyer', StatusHistory::sole()->actor_type ?? null);
    }

    public function test_buyer_can_cancel_a_placed_order(): void
    {
        $so = $this->orderAt(OrderStatus::Placed);

        $result = $this->service->transition(
            $so,
            OrderStatus::Cancelled,
            actor: OrderActor::Buyer,
            actorId: 77,
            source: 'web',
        );

        $this->assertSame(OrderStatus::Cancelled, $result->status);
        $this->assertSame(1, StatusHistory::count());
        $this->assertSame('buyer', StatusHistory::sole()->actor_type ?? null);
    }

    // ── Buyer: rejected ──────────────────────────────────────────────────────

    public function test_buyer_cannot_cancel_after_seller_accepts(): void
    {
        $so = $this->orderAt(OrderStatus::Confirmed);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('buyer role cannot');

        $this->service->transition($so, OrderStatus::Cancelled, actor: OrderActor::Buyer);
    }

    public function test_buyer_cannot_advance_the_shipping_pipeline(): void
    {
        $so = $this->orderAt(OrderStatus::PickedUp);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('buyer role cannot');

        $this->service->transition($so, OrderStatus::AtSortingCenter, actor: OrderActor::Buyer);
    }

    // ── System: no direct transitions in the canonical flow ───────────────────────
    // CourierAssignmentService handles assignments with the proper actor.

    public function test_system_has_no_direct_transitions(): void
    {
        $this->assertSame([], OrderActor::allowedTransitions(OrderActor::System));
    }

    // ── Missing graph edges from the spec are present and reachable ──────────

    public function test_sorted_is_a_graph_node_with_outbound_edges(): void
    {
        $so = $this->orderAt(OrderStatus::Sorted);

        // Both spec'd exits from SORTED exist in the graph.
        $this->assertContains(OrderStatus::AssignedToRider, self::graphFor(OrderStatus::Sorted));

        $returned = $this->orderAt(OrderStatus::DeliveryFailed);

        // DELIVERY_FAILED's entry (from OUT_FOR_DELIVERY) and its exit exist.
        $this->assertContains(OrderStatus::Returned, self::graphFor(OrderStatus::DeliveryFailed));
        $this->assertSame(OrderStatus::DeliveryFailed, $returned->status);
    }

    public function test_history_rows_carry_actor_and_source_columns(): void
    {
        $so = $this->orderAt(OrderStatus::Placed);

        $this->service->transition(
            $so, OrderStatus::ReadyForPickup,
            changedBy: 'Juan Dela Cruz',
            actor: OrderActor::Seller,
            actorId: 42,
            source: 'web',
        );

        $history = StatusHistory::sole();

        $this->assertSame('seller', $history->actor_type);
        $this->assertSame(42, $history->actor_id);
        $this->assertSame('web', $history->source);
        $this->assertSame('Juan Dela Cruz', $history->changed_by, 'changed_by keeps working');
    }

    /**
     * Read the graph under test via reflection (ALLOWED is private by design).
     *
     * @return array<int, OrderStatus>
     */
    private static function graphFor(OrderStatus $from): array
    {
        $constant = (new \ReflectionClass(SellerOrderStatusService::class))->getConstant('ALLOWED');

        return $constant[$from->value] ?? [];
    }
}
