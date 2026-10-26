<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Notifications\Buyer\SellerOrderCancelled;
use App\Services\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * F1 — Seller can decline their seller_order slice: cancel, restore stock,
 * void pending payment; sibling parcels continue.
 */
class SellerOrderDeclineTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{0: Buyer, 1: Seller, 2: Seller, 3: ProductVariant, 4: SellerOrder, 5: SellerOrder, 6: Order}
     */
    private function multiSellerCheckout(int $stockA = 5, int $qtyA = 2): array
    {
        $buyer = Buyer::factory()->create();
        $sellerA = Seller::factory()->create(['status' => 'approved']);
        $sellerB = Seller::factory()->create(['status' => 'approved']);

        $productA = Product::factory()->create([
            'seller_id' => $sellerA->id,
            'stock_quantity' => $stockA,
            'status' => 'active',
        ]);
        $variantA = ProductVariant::query()
            ->where('product_id', $productA->id)
            ->orderBy('id')
            ->firstOrFail();
        $variantA->forceFill(['stock_quantity' => $stockA - $qtyA])->save();
        $productA->forceFill(['stock_quantity' => $stockA - $qtyA])->save();

        $productB = Product::factory()->create([
            'seller_id' => $sellerB->id,
            'stock_quantity' => 3,
            'status' => 'active',
        ]);
        $variantB = ProductVariant::query()
            ->where('product_id', $productB->id)
            ->orderBy('id')
            ->firstOrFail();
        $variantB->forceFill(['stock_quantity' => 2])->save();
        $productB->forceFill(['stock_quantity' => 2])->save();

        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'status' => OrderStatus::Placed,
        ]);

        $soA = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $sellerA->id,
            'status' => OrderStatus::Placed,
        ]);
        OrderItem::factory()->create([
            'seller_order_id' => $soA->id,
            'product_id' => $productA->id,
            'variant_id' => $variantA->id,
            'quantity' => $qtyA,
        ]);
        Payment::factory()->create([
            'seller_order_id' => $soA->id,
            'method' => PaymentMethod::Cod,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 2000,
        ]);

        $soB = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $sellerB->id,
            'status' => OrderStatus::Placed,
        ]);
        OrderItem::factory()->create([
            'seller_order_id' => $soB->id,
            'product_id' => $productB->id,
            'variant_id' => $variantB->id,
            'quantity' => 1,
        ]);
        Payment::factory()->create([
            'seller_order_id' => $soB->id,
            'method' => PaymentMethod::Cod,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 1000,
        ]);

        return [$buyer, $sellerA, $sellerB, $variantA->fresh(), $soA->fresh(), $soB->fresh(), $order->fresh()];
    }

    public function test_seller_can_decline_placed_order_restoring_stock_and_notifying_buyer(): void
    {
        Notification::fake();

        [$buyer, $sellerA, , $variantA, $soA, $soB, $order] = $this->multiSellerCheckout(5, 2);

        $stockBefore = (int) $variantA->stock_quantity;

        $this->actingAs($sellerA, 'seller')
            ->post(route('seller.orders.decline', $soA), ['reason' => 'Out of materials'])
            ->assertRedirect()
            ->assertSessionHas('declined');

        $soA->refresh();
        $soB->refresh();
        $order->refresh();
        $variantA->refresh();

        $this->assertSame(OrderStatus::Cancelled, $soA->status);
        $this->assertSame(OrderStatus::Placed, $soB->status, 'sibling seller slice continues');
        $this->assertSame($stockBefore + 2, (int) $variantA->stock_quantity);

        $ledger = InventoryTransaction::query()
            ->where('seller_order_id', $soA->id)
            ->where('reason', 'cancel')
            ->sole();
        $this->assertSame(2, $ledger->delta);

        $this->assertSame(PaymentStatus::Cancelled, $soA->payment->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $soB->payment->fresh()->status);

        // Parent rollup ignores cancelled sibling and stays on the active slice.
        $this->assertSame(OrderStatus::Placed, $order->computeRollupStatus());

        Notification::assertSentToTimes($buyer, SellerOrderCancelled::class, 1);
    }

    public function test_replayed_cancel_sends_exactly_one_notification(): void
    {
        Notification::fake();

        [$buyer, , , , $soA] = $this->multiSellerCheckout();

        $service = new SellerOrderStatusService;

        $service->transition(
            $soA,
            OrderStatus::Cancelled,
            note: 'Seller declined: first',
            changedBy: 'seller',
            actor: OrderActor::Seller,
            actorId: 1,
            source: 'web',
            expectedFrom: OrderStatus::Placed,
        );

        // Concurrent/replayed hop: already CANCELLED under the lock.
        $service->transition(
            $soA->fresh(),
            OrderStatus::Cancelled,
            note: 'Seller declined: replay',
            changedBy: 'seller',
            actor: OrderActor::Seller,
            actorId: 1,
            source: 'web',
            expectedFrom: OrderStatus::Placed,
        );

        Notification::assertSentToTimes($buyer, SellerOrderCancelled::class, 1);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'cancel')->count());
        $this->assertSame(PaymentStatus::Cancelled, $soA->payment->fresh()->status);
    }

    public function test_decline_requires_a_reason(): void
    {
        [, $sellerA, , , $soA] = $this->multiSellerCheckout();

        $this->actingAs($sellerA, 'seller')
            ->postJson(route('seller.orders.decline', $soA), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertSame(OrderStatus::Placed, $soA->fresh()->status);
    }

    public function test_guest_cannot_decline_an_order(): void
    {
        [, , , , $soA] = $this->multiSellerCheckout();

        $this->post(route('seller.orders.decline', $soA), ['reason' => 'Nope'])
            ->assertRedirect();

        $this->assertGuest('seller');
        $this->assertSame(OrderStatus::Placed, $soA->fresh()->status);
    }

    public function test_decline_after_ready_for_pickup_is_rejected(): void
    {
        [, $sellerA, , , $soA] = $this->multiSellerCheckout();

        $soA->forceFill(['status' => OrderStatus::ReadyForPickup->value])->save();

        $this->actingAs($sellerA, 'seller')
            ->post(route('seller.orders.decline', $soA), ['reason' => 'Too late'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(OrderStatus::ReadyForPickup, $soA->fresh()->status);
        $this->assertSame(0, InventoryTransaction::query()->where('reason', 'cancel')->count());
    }

    public function test_foreign_seller_cannot_decline_another_shops_order(): void
    {
        [, , $sellerB, , $soA] = $this->multiSellerCheckout();

        $this->actingAs($sellerB, 'seller')
            ->post(route('seller.orders.decline', $soA), ['reason' => 'Not mine'])
            ->assertForbidden();

        $this->assertSame(OrderStatus::Placed, $soA->fresh()->status);
    }

    public function test_all_cancelled_order_rollups_to_cancelled(): void
    {
        [, $sellerA, $sellerB, , $soA, $soB, $order] = $this->multiSellerCheckout();

        $this->actingAs($sellerA, 'seller')
            ->post(route('seller.orders.decline', $soA), ['reason' => 'No stock'])
            ->assertSessionHas('declined');

        $this->actingAs($sellerB, 'seller')
            ->post(route('seller.orders.decline', $soB), ['reason' => 'Closed'])
            ->assertSessionHas('declined');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->computeRollupStatus());
    }
}
