<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Courier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\StatusHistory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Slice-0 hardening: double-clicking "Mark ready" must not write a second
 * ReadyForPickup hop or reassign anything; an illegal transition shows a
 * friendly flash message, never a 500.
 */
class MarkReadyDoubleSubmitTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Seller $seller;

    private SellerOrder $sellerOrder;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = Seller::factory()->create();
        $this->product = Product::factory()->create([
            'seller_id' => $this->seller->id,
            'weight_grams' => 2000,
        ]);
        $this->sellerOrder = $this->makeOrder();
    }

    /**
     * A realistic order parcel: a 2 kg product for a Quezon City buyer.
     */
    private function makeOrder(): SellerOrder
    {
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

    private function actingSeller(): self
    {
        return $this->actingAs($this->seller, 'seller');
    }

    public function test_a_second_mark_ready_does_not_create_a_duplicate_history_row(): void
    {
        // First click: packs the order to ReadyForPickup (no courier assignment).
        $this->actingSeller()->post(route('seller.orders.markReady', $this->sellerOrder));
        $this->sellerOrder->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $this->sellerOrder->status);
        $this->assertNull($this->sellerOrder->courier_id);

        // Second (double-clicked) request: must early-return cleanly, no
        // second history row, no error flash.
        $this->actingSeller()
            ->post(route('seller.orders.markReady', $this->sellerOrder))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->sellerOrder->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $this->sellerOrder->status);
        $this->assertNull($this->sellerOrder->courier_id);
        $this->assertSame(1, StatusHistory::where('seller_order_id', $this->sellerOrder->id)->count(), 'still exactly one hop');
    }

    public function test_double_click_before_assignment_marks_ready_only_once(): void
    {
        // No couriers exist — markReady transitions to ReadyForPickup without
        // any auto-assignment. Double click must be a clean no-op.
        $this->actingSeller()->post(route('seller.orders.markReady', $this->sellerOrder));
        $this->actingSeller()
            ->post(route('seller.orders.markReady', $this->sellerOrder))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->sellerOrder->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $this->sellerOrder->status);
        $this->assertNull($this->sellerOrder->courier_id);
        $this->assertSame(1, StatusHistory::where('seller_order_id', $this->sellerOrder->id)->count());
    }

    public function test_an_illegal_transition_shows_a_friendly_flash_not_a_500(): void
    {
        // Force the order past the seller's stage (simulates the courier path
        // having already started).
        $this->sellerOrder->forceFill(['status' => OrderStatus::PickedUp->value])->save();

        $this->actingSeller()
            ->post(route('seller.orders.markReady', $this->sellerOrder))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertSame(OrderStatus::PickedUp, $this->sellerOrder->refresh()->status, 'status unchanged');
    }

    public function test_another_sellers_mark_ready_is_rejected_by_the_policy(): void
    {
        $other = Seller::factory()->create();

        $this->actingAs($other, 'seller')
            ->post(route('seller.orders.markReady', $this->sellerOrder))
            ->assertForbidden();

        $this->assertSame(OrderStatus::Placed, $this->sellerOrder->refresh()->status);
    }

    public function test_accept_order_drives_placed_to_confirming_to_preparing(): void
    {
        // Accept Order: Placed → Confirmed → Preparing.
        $this->actingSeller()
            ->post(route('seller.orders.accept', $this->sellerOrder))
            ->assertRedirect()
            ->assertSessionHas('accepted');

        $this->sellerOrder->refresh();
        $this->assertSame(OrderStatus::Preparing, $this->sellerOrder->status);

        // Two history rows: Placed → Confirmed → Preparing.
        $this->assertSame(2, StatusHistory::where('seller_order_id', $this->sellerOrder->id)->count());
    }

    public function test_accept_order_is_rejected_on_non_placed_status(): void
    {
        // Move the order past Placed manually.
        $this->sellerOrder->forceFill(['status' => OrderStatus::Confirmed->value])->save();

        $this->actingSeller()
            ->post(route('seller.orders.accept', $this->sellerOrder))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(OrderStatus::Confirmed, $this->sellerOrder->refresh()->status);
    }
}
