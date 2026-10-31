<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Notifications\Buyer\SellerOrderCancelled;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string, array{0: OrderStatus}> */
    public static function postPickupStatuses(): array
    {
        return [
            'picked up' => [OrderStatus::PickedUp],
            'at sorting center' => [OrderStatus::AtSortingCenter],
            'out for delivery' => [OrderStatus::OutForDelivery],
            'delivered' => [OrderStatus::Delivered],
        ];
    }

    #[DataProvider('postPickupStatuses')]
    public function test_sel_ord_006_decline_after_pickup_is_rejected(OrderStatus $status): void
    {
        [, $sellerA, , , $soA] = $this->multiSellerCheckout();
        $voucher = Voucher::factory()->create(['times_used' => 1]);

        $soA->forceFill(['status' => $status->value, 'shop_voucher_id' => $voucher->id])->save();

        $this->actingAs($sellerA, 'seller')
            ->post(route('seller.orders.decline', $soA), ['reason' => 'Too late to cancel'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($status, $soA->fresh()->status);
        $this->assertSame(0, InventoryTransaction::query()->where('reason', 'cancel')->count());
        $this->assertSame(1, $voucher->fresh()->times_used);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function reasonLengths(): array
    {
        return [
            '9 characters' => [str_repeat('a', 9), false],
            '10 characters' => [str_repeat('a', 10), true],
            '300 characters' => [str_repeat('a', 300), true],
            '301 characters' => [str_repeat('a', 301), false],
            'only spaces' => [str_repeat(' ', 12), false],
        ];
    }

    #[DataProvider('reasonLengths')]
    public function test_sel_ord_004_reason_must_be_10_to_300_characters(string $reason, bool $accepted): void
    {
        Notification::fake();

        [, $sellerA, , , $soA] = $this->multiSellerCheckout();

        $response = $this->actingAs($sellerA, 'seller')
            ->post(route('seller.orders.decline', $soA), ['reason' => $reason]);

        if ($accepted) {
            $response->assertSessionHas('declined');
            $this->assertSame(OrderStatus::Cancelled, $soA->fresh()->status);
        } else {
            $response->assertSessionHasErrors('reason');
            $this->assertSame(OrderStatus::Placed, $soA->fresh()->status);
        }
    }

    public function test_sel_ord_004_seller_cancel_returns_shop_voucher_immediately(): void
    {
        Notification::fake();

        [, $sellerA, , , $soA, $soB] = $this->multiSellerCheckout();
        $shopA = Voucher::factory()->create(['times_used' => 1]);
        $shopB = Voucher::factory()->create(['times_used' => 1]);
        $soA->forceFill(['shop_voucher_id' => $shopA->id])->save();
        $soB->forceFill(['shop_voucher_id' => $shopB->id])->save();

        $this->actingAs($sellerA, 'seller')
            ->post(route('seller.orders.decline', $soA), ['reason' => 'Out of materials'])
            ->assertSessionHas('declined');

        $this->assertSame(0, $shopA->fresh()->times_used);
        $this->assertSame(1, $shopB->fresh()->times_used, 'sibling shop voucher stays consumed');
    }

    public function test_sel_ord_004_shared_vouchers_return_once_after_the_last_active_slice(): void
    {
        Notification::fake();

        [, $sellerA, $sellerB, , $soA, $soB] = $this->multiSellerCheckout();
        $platform = Voucher::factory()->create(['times_used' => 3]);
        $shipping = Voucher::factory()->create(['times_used' => 3]);
        foreach ([$soA, $soB] as $slice) {
            $slice->forceFill(['platform_voucher_id' => $platform->id, 'shipping_voucher_id' => $shipping->id])->save();
        }

        $this->actingAs($sellerA, 'seller')
            ->post(route('seller.orders.decline', $soA), ['reason' => 'Out of materials'])
            ->assertSessionHas('declined');

        $this->assertSame(3, $platform->fresh()->times_used, 'the other slice still uses the voucher');
        $this->assertSame(3, $shipping->fresh()->times_used, 'the other slice still uses the voucher');

        $this->actingAs($sellerB, 'seller')
            ->post(route('seller.orders.decline', $soB), ['reason' => 'Shop is closed today'])
            ->assertSessionHas('declined');

        $this->assertSame(2, $platform->fresh()->times_used);
        $this->assertSame(2, $shipping->fresh()->times_used);
    }

    public function test_sel_ord_004_replayed_cancel_returns_voucher_once(): void
    {
        Notification::fake();

        [, , , , $soA] = $this->multiSellerCheckout();
        $voucher = Voucher::factory()->create(['times_used' => 2]);
        $soA->forceFill(['shop_voucher_id' => $voucher->id])->save();
        $stale = $soA->fresh();

        foreach ([$soA->fresh(), $stale] as $copy) {
            (new SellerOrderStatusService)->transition(
                $copy,
                OrderStatus::Cancelled,
                actor: OrderActor::Seller,
                actorId: 1,
                expectedFrom: OrderStatus::Placed,
            );
        }

        $this->assertSame(1, $voucher->fresh()->times_used);
    }

    public function test_sel_ord_001_tabs_show_counts_and_filter_orders(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $counts = [
            OrderStatus::Placed->value => 2,
            OrderStatus::Preparing->value => 1,
            OrderStatus::ReadyForPickup->value => 1,
            OrderStatus::OutForDelivery->value => 1,
            OrderStatus::Completed->value => 1,
            OrderStatus::Cancelled->value => 2,
        ];
        foreach ($counts as $status => $count) {
            SellerOrder::factory()->count($count)->create(['seller_id' => $seller->id, 'status' => $status]);
        }
        SellerOrder::factory()->create(['status' => OrderStatus::Placed->value]);

        $expected = ['all' => 8, 'to_ship' => 3, 'ready' => 1, 'shipped' => 1, 'delivered' => 1, 'cancelled' => 2];

        $this->actingAs($seller, 'seller');

        foreach ($expected as $bucket => $count) {
            $response = $this->get(route('seller.orders.index', ['bucket' => $bucket]))
                ->assertOk()
                ->assertViewHas('bucket', $bucket)
                ->assertViewHas('tabCounts', $expected);

            $this->assertCount($count, $response->viewData('sellerOrders'));
        }

        $this->get(route('seller.orders.index', ['bucket' => 'cancelled']))
            ->assertSeeInOrder(['All orders (8)', 'To ship (3)', 'Ready for pickup (1)', 'Shipped (1)', 'Delivered (1)', 'Cancelled (2)']);
    }

    public function test_sel_ord_001_unknown_bucket_falls_back_to_all(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        SellerOrder::factory()->create(['seller_id' => $seller->id, 'status' => OrderStatus::Placed->value]);

        $this->actingAs($seller, 'seller')
            ->get(route('seller.orders.index', ['bucket' => 'nonsense']))
            ->assertOk()
            ->assertViewHas('bucket', 'all')
            ->assertViewHas('sellerOrders', fn ($orders) => $orders->count() === 1);

        $this->get(route('seller.orders.index', ['bucket' => ['to_ship']]))
            ->assertOk()
            ->assertViewHas('bucket', 'all');
    }

    public function test_sel_ord_004_order_page_shows_the_reason_error(): void
    {
        [, $sellerA, , , $soA] = $this->multiSellerCheckout();

        $this->actingAs($sellerA, 'seller')
            ->from(route('seller.orders.show', $soA))
            ->followingRedirects()
            ->post(route('seller.orders.decline', $soA), ['reason' => 'Too short'])
            ->assertOk()
            ->assertSee('The cancellation reason must be at least 10 characters.');
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
            ->post(route('seller.orders.decline', $soA), ['reason' => 'No stock left'])
            ->assertSessionHas('declined');

        $this->actingAs($sellerB, 'seller')
            ->post(route('seller.orders.decline', $soB), ['reason' => 'Shop is closed today'])
            ->assertSessionHas('declined');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->computeRollupStatus());
    }

    public function test_order_list_shows_oldest_created_orders_first_with_id_as_tiebreaker(): void
    {
        $this->withoutVite();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $newest = SellerOrder::factory()->create(['seller_id' => $seller->id, 'status' => OrderStatus::Placed, 'created_at' => now()]);
        $older = SellerOrder::factory()->create(['seller_id' => $seller->id, 'status' => OrderStatus::Placed, 'created_at' => now()->subDay()]);
        $sameTime = SellerOrder::factory()->create(['seller_id' => $seller->id, 'status' => OrderStatus::Confirmed, 'created_at' => $older->created_at]);
        SellerOrder::factory()->create(['created_at' => now()->subWeek()]);

        foreach (['all', 'to_ship'] as $bucket) {
            $this->actingAs($seller, 'seller')->get(route('seller.orders.index', ['bucket' => $bucket]))
                ->assertOk()->assertViewHas('sellerOrders', fn ($orders) => $orders->pluck('id')->all() === [$older->id, $sameTime->id, $newest->id]);
        }
    }

    public function test_orders_still_to_prepare_lead_then_those_on_the_way_then_finished_ones(): void
    {
        $this->withoutVite();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $order = fn (OrderStatus $status, int $daysOld) => SellerOrder::factory()->create([
            'seller_id' => $seller->id, 'status' => $status, 'created_at' => now()->subDays($daysOld),
        ])->id;

        $oldestDone = $order(OrderStatus::Completed, 30);
        $cancelled = $order(OrderStatus::Cancelled, 20);
        $delivered = $order(OrderStatus::Delivered, 2);
        $returned = $order(OrderStatus::Returned, 1);
        $shippedOld = $order(OrderStatus::PickedUp, 10);
        $failed = $order(OrderStatus::DeliveryFailed, 9);
        $ready = $order(OrderStatus::ReadyForPickup, 3);
        $preparing = $order(OrderStatus::Preparing, 5);
        $placedOld = $order(OrderStatus::Placed, 6);
        $placedNew = $order(OrderStatus::Placed, 0);

        $this->actingAs($seller, 'seller')->get(route('seller.orders.index'))->assertOk()->assertViewHas(
            'sellerOrders',
            fn ($orders) => $orders->pluck('id')->all() === [$placedOld, $preparing, $placedNew, $shippedOld, $failed, $ready, $oldestDone, $cancelled, $delivered, $returned],
        );
    }

    public function test_order_list_shows_only_the_important_columns(): void
    {
        $this->withoutVite();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $order = SellerOrder::factory()->create(['seller_id' => $seller->id, 'status' => OrderStatus::Placed, 'tracking_number' => 'TRK-HIDDEN-123']);
        foreach (['Tulfone', 'Tulfone', 'Pixel Case', 'Charger'] as $name) {
            OrderItem::factory()->create(['seller_order_id' => $order->id, 'product_name' => $name, 'quantity' => 2]);
        }

        $this->actingAs($seller, 'seller')->get(route('seller.orders.index'))->assertOk()
            ->assertSeeInOrder(['>Order</th>', '>Buyer</th>', '>Items</th>', '>Total</th>', '>Status</th>'], false)
            ->assertDontSee('>Tracking</th>', false)
            ->assertDontSee('TRK-HIDDEN-123')
            ->assertSee('Tulfone +2 more')
            ->assertSee('Qty 8')
            ->assertSee('Accept order')
            ->assertSee('href="'.route('seller.orders.show', $order).'"', false);

        $this->get(route('seller.orders.show', $order))->assertOk()->assertSee('TRK-HIDDEN-123');
    }

    public function test_oldest_first_ordering_continues_across_pages(): void
    {
        $this->withoutVite();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $ids = [];
        for ($age = 0; $age < 16; $age++) {
            $ids[] = SellerOrder::factory()->create(['seller_id' => $seller->id, 'created_at' => now()->subMinutes($age)])->id;
        }
        $expected = array_reverse($ids);
        $this->actingAs($seller, 'seller')->get(route('seller.orders.index'))
            ->assertOk()->assertViewHas('sellerOrders', fn ($orders) => $orders->pluck('id')->all() === array_slice($expected, 0, 15));
        $this->get(route('seller.orders.index', ['page' => 2]))
            ->assertOk()->assertViewHas('sellerOrders', fn ($orders) => $orders->pluck('id')->all() === array_slice($expected, 15));
    }
}
