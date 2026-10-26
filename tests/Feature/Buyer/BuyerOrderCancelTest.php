<?php

namespace Tests\Feature\Buyer;

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
use App\Models\StatusHistory;
use App\Notifications\Seller\BuyerOrderCancelled;
use App\Services\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * F3 — Buyer can cancel their own seller_order slice while still PLACED:
 * restore stock once, void pending COD, notify the seller; siblings continue.
 */
class BuyerOrderCancelTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{0: Buyer, 1: Seller, 2: Seller, 3: ProductVariant, 4: SellerOrder, 5: SellerOrder, 6: Order}
     */
    private function multiSellerCheckout(int $stockA = 5, int $qtyA = 2): array
    {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
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

    public function test_buyer_can_cancel_placed_slice_restoring_stock_and_notifying_seller(): void
    {
        Notification::fake();

        [$buyer, $sellerA, , $variantA, $soA, $soB, $order] = $this->multiSellerCheckout(5, 2);

        $stockBefore = (int) $variantA->stock_quantity;

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.orders.cancelSellerOrder', [$order, $soA]), [
                'reason' => 'Changed my mind',
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'status' => OrderStatus::Cancelled->value,
            ]);

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

        $history = StatusHistory::query()
            ->where('seller_order_id', $soA->id)
            ->where('to_status', OrderStatus::Cancelled->value)
            ->sole();
        $this->assertSame('buyer', $history->actor_type);
        $this->assertSame((int) $buyer->id, (int) $history->actor_id);
        $this->assertSame(OrderStatus::Placed, $history->from_status);
        $this->assertStringContainsString('Changed my mind', (string) $history->note);

        $this->assertSame(OrderStatus::Placed, $order->computeRollupStatus());

        Notification::assertSentToTimes($sellerA, BuyerOrderCancelled::class, 1);

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Cancelled by Buyer')
            ->assertSee('Changed my mind')
            ->assertDontSee(route('buyer.orders.cancelSellerOrder', [$order, $soA]), false);
    }

    public function test_replayed_cancel_restores_stock_and_notifies_once(): void
    {
        Notification::fake();

        [$buyer, $sellerA, , , $soA, , $order] = $this->multiSellerCheckout();

        $service = new SellerOrderStatusService;

        $service->transition(
            $soA,
            OrderStatus::Cancelled,
            note: 'Buyer cancelled: first',
            changedBy: 'buyer',
            actor: OrderActor::Buyer,
            actorId: (int) $buyer->id,
            source: 'web',
            expectedFrom: OrderStatus::Placed,
        );

        $service->transition(
            $soA->fresh(),
            OrderStatus::Cancelled,
            note: 'Buyer cancelled: replay',
            changedBy: 'buyer',
            actor: OrderActor::Buyer,
            actorId: (int) $buyer->id,
            source: 'web',
            expectedFrom: OrderStatus::Placed,
        );

        // HTTP double-submit after already cancelled: controller rejects non-PLACED.
        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.orders.cancelSellerOrder', [$order, $soA]), [
                'reason' => 'Again',
            ])
            ->assertStatus(422);

        Notification::assertSentToTimes($sellerA, BuyerOrderCancelled::class, 1);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'cancel')->count());
        $this->assertSame(PaymentStatus::Cancelled, $soA->payment->fresh()->status);
    }

    public function test_cancel_requires_a_reason(): void
    {
        [$buyer, , , , $soA, , $order] = $this->multiSellerCheckout();

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.orders.cancelSellerOrder', [$order, $soA]), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->assertSame(OrderStatus::Placed, $soA->fresh()->status);
    }

    public function test_guest_cannot_cancel_an_order(): void
    {
        [, , , , $soA, , $order] = $this->multiSellerCheckout();

        $this->post(route('buyer.orders.cancelSellerOrder', [$order, $soA]), [
            'reason' => 'Nope',
        ])->assertRedirect();

        $this->assertGuest('buyer');
        $this->assertSame(OrderStatus::Placed, $soA->fresh()->status);
    }

    public function test_cancel_after_seller_accepts_is_rejected(): void
    {
        [$buyer, , , , $soA, , $order] = $this->multiSellerCheckout();

        $soA->forceFill(['status' => OrderStatus::Confirmed->value])->save();

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.orders.cancelSellerOrder', [$order, $soA]), [
                'reason' => 'Too late',
            ])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::Confirmed, $soA->fresh()->status);
        $this->assertSame(0, InventoryTransaction::query()->where('reason', 'cancel')->count());
    }

    public function test_foreign_buyer_cannot_cancel_another_buyers_order(): void
    {
        [, , , , $soA, , $order] = $this->multiSellerCheckout();
        $other = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($other, 'buyer')
            ->postJson(route('buyer.orders.cancelSellerOrder', [$order, $soA]), [
                'reason' => 'Not mine',
            ])
            ->assertNotFound();

        $this->assertSame(OrderStatus::Placed, $soA->fresh()->status);
    }

    public function test_seller_order_must_belong_to_parent_order(): void
    {
        [$buyer, , , , $soA, , $order] = $this->multiSellerCheckout();
        $otherOrder = Order::factory()->create(['buyer_id' => $buyer->id]);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.orders.cancelSellerOrder', [$otherOrder, $soA]), [
                'reason' => 'Wrong parent',
            ])
            ->assertNotFound();

        $this->assertSame(OrderStatus::Placed, $soA->fresh()->status);
    }

    public function test_multi_seller_cancel_leaves_confirmed_sibling_untouched(): void
    {
        Notification::fake();

        [$buyer, $sellerA, $sellerB, , $soA, $soB, $order] = $this->multiSellerCheckout();

        $soB->forceFill(['status' => OrderStatus::Confirmed->value])->save();

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.orders.cancelSellerOrder', [$order, $soA]), [
                'reason' => 'Only cancel A',
            ])
            ->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $soA->fresh()->status);
        $this->assertSame(OrderStatus::Confirmed, $soB->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $soB->payment->fresh()->status);
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->computeRollupStatus());

        Notification::assertSentToTimes($sellerA, BuyerOrderCancelled::class, 1);
        Notification::assertNotSentTo($sellerB, BuyerOrderCancelled::class);
    }

    public function test_show_page_offers_cancel_only_while_placed(): void
    {
        [$buyer, , , , $soA, $soB, $order] = $this->multiSellerCheckout();
        $soB->forceFill(['status' => OrderStatus::Confirmed->value])->save();

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee(route('buyer.orders.cancelSellerOrder', [$order, $soA]), false)
            ->assertDontSee(route('buyer.orders.cancelSellerOrder', [$order, $soB]), false);
    }
}
