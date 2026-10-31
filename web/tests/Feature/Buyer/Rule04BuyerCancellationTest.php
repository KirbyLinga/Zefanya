<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Models\Shared\Voucher;
use App\Notifications\Seller\BuyerOrderCancelled;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Rule04BuyerCancellationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function parcel(OrderStatus $status = OrderStatus::Confirmed, ?Order $parent = null): SellerOrder
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 18]);
        $variant = $product->variants()->sole();
        $parent ??= Order::factory()->create(['buyer_id' => Buyer::factory()->create()->id]);
        $parcel = SellerOrder::factory()->status($status)->create(['seller_id' => $seller->id, 'order_id' => $parent->id]);
        OrderItem::factory()->create([
            'seller_order_id' => $parcel->id, 'product_id' => $product->id,
            'variant_id' => $variant->id, 'quantity' => 2,
        ]);
        Payment::factory()->create(['seller_order_id' => $parcel->id, 'method' => PaymentMethod::Cod, 'status' => PaymentStatus::Pending]);

        return $parcel->fresh(['order.buyer', 'seller', 'orderItems.variant', 'payment']);
    }

    private function cancel(SellerOrder $parcel, string $reason = 'Changed my mind')
    {
        return $this->postJson(route('buyer.orders.cancelSellerOrder', [$parcel->order, $parcel]), ['reason' => $reason]);
    }

    public function test_buy_can_001_accepted_cod_cancels_for_both_roles_restores_twenty_and_notifies_with_reason(): void
    {
        $parcel = $this->parcel();
        $variant = $parcel->orderItems->sole()->variant;
        $before = $parcel->payment->getRawOriginal();
        $this->actingAs($parcel->order->buyer, 'buyer');

        $this->cancel($parcel)->assertOk()->assertJsonPath('status', 'CANCELLED');

        $this->assertSame(OrderStatus::Cancelled, $parcel->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $parcel->order->fresh()->status);
        $this->assertSame(20, $variant->fresh()->stock_quantity);
        $this->assertSame(20, $variant->product->fresh()->stock_quantity);
        $payment = $parcel->payment->fresh();
        $this->assertSame(PaymentStatus::Cancelled, $payment->status);
        $this->assertSame($before['amount_minor'], $payment->getRawOriginal('amount_minor'));
        $this->assertNull($payment->paid_at);
        $this->assertNull($payment->collected_at);
        $ledger = InventoryTransaction::where('seller_order_id', $parcel->id)->where('reason', 'cancel')->sole();
        $this->assertSame([18, 20, 2], [$ledger->quantity_before, $ledger->quantity_after, $ledger->delta]);
        $history = StatusHistory::where('seller_order_id', $parcel->id)->sole();
        $this->assertSame(OrderStatus::Confirmed, $history->from_status);
        $this->assertSame('buyer', $history->actor_type);
        $this->assertSame($parcel->order->buyer_id, $history->actor_id);
        $this->assertSame('Buyer cancelled: Changed my mind', $history->note);
        $notification = $parcel->seller->notifications()->sole();
        $this->assertSame('Changed my mind', $notification->data['reason']);
        $this->assertStringContainsString('Changed my mind', $notification->data['message']);
        $this->get(route('buyer.orders.show', $parcel->order))->assertOk()->assertSee('Cancelled by Buyer')->assertSee('Changed my mind');
        $this->actingAs($parcel->seller, 'seller')->get(route('seller.orders.show', $parcel))->assertOk()->assertSee('Cancelled')->assertDontSee('You declined this order.');
    }

    public static function buyerCancellationStates(): array
    {
        return array_map(fn (OrderStatus $status): array => [$status, in_array($status, [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Preparing], true)], OrderStatus::cases());
    }

    #[DataProvider('buyerCancellationStates')]
    public function test_a6_buyer_cancel_visibility_and_direct_http_enforce_the_ready_for_pickup_boundary(OrderStatus $status, bool $allowed): void
    {
        Notification::fake();
        $parcel = $this->parcel($status);
        $url = route('buyer.orders.cancelSellerOrder', [$parcel->order, $parcel]);
        $this->actingAs($parcel->order->buyer, 'buyer');
        $page = $this->get(route('buyer.orders.show', $parcel->order))->assertOk();

        if ($allowed) {
            $page->assertSee('data-cancel-url="'.$url.'"', false);
            $this->cancel($parcel)->assertOk();
            $this->assertSame(OrderStatus::Cancelled, $parcel->fresh()->status);
            $this->assertSame(20, $parcel->orderItems->sole()->variant->fresh()->stock_quantity);
            Notification::assertSentToTimes($parcel->seller, BuyerOrderCancelled::class, 1);
        } else {
            $page->assertDontSee('data-cancel-url="'.$url.'"', false);
            $this->cancel($parcel)->assertUnprocessable();
            $this->assertSame($status, $parcel->fresh()->status);
            $this->assertSame(PaymentStatus::Pending, $parcel->payment->fresh()->status);
            $this->assertSame(18, $parcel->orderItems->sole()->variant->fresh()->stock_quantity);
            $this->assertDatabaseCount('status_histories', 0);
            $this->assertDatabaseCount('inventory_transactions', 0);
            Notification::assertNothingSent();
        }
    }

    public function test_buy_can_006_http_double_click_and_stale_service_replay_restore_stock_once(): void
    {
        Notification::fake();
        $parcel = $this->parcel();
        $stale = $parcel->fresh();
        $voucher = Voucher::factory()->create(['times_used' => 1]);
        $parcel->forceFill(['voucher_id' => $voucher->id, 'voucher_discount_minor' => 100])->save();
        $snapshot = $parcel->getRawOriginal();
        $this->actingAs($parcel->order->buyer, 'buyer');
        $this->cancel($parcel)->assertOk();
        $usageAfterCancellation = $voucher->fresh()->times_used;
        $historyBefore = StatusHistory::sole()->getRawOriginal();

        $this->cancel($parcel, 'Duplicate click')->assertUnprocessable();
        app(SellerOrderStatusService::class)->transition($stale, OrderStatus::Cancelled,
            actor: OrderActor::Buyer, actorId: $parcel->order->buyer_id, note: 'Duplicate service call');

        $this->assertSame(20, $parcel->orderItems->sole()->variant->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseCount('status_histories', 1);
        $this->assertSame($historyBefore, StatusHistory::sole()->getRawOriginal());
        $this->assertSame($usageAfterCancellation, $voucher->fresh()->times_used);
        $this->assertSame($snapshot['voucher_discount_minor'], $parcel->fresh()->getRawOriginal('voucher_discount_minor'));
        $this->assertSame(PaymentStatus::Cancelled, $parcel->payment->fresh()->status);
        Notification::assertSentToTimes($parcel->seller, BuyerOrderCancelled::class, 1);
    }

    public function test_cancelling_one_accepted_slice_preserves_sibling_inventory_payment_vouchers_and_rollup(): void
    {
        Notification::fake();
        $first = $this->parcel();
        $second = $this->parcel(OrderStatus::Preparing, $first->order);
        $voucher = Voucher::factory()->create(['times_used' => 1]);
        foreach ([$first, $second] as $slice) {
            $slice->forceFill(['voucher_id' => $voucher->id, 'platform_voucher_id' => $voucher->id, 'voucher_discount_minor' => 100])->save();
        }
        $siblingBefore = $second->fresh()->getRawOriginal();
        $siblingPaymentBefore = $second->payment->fresh()->getRawOriginal();
        $this->actingAs($first->order->buyer, 'buyer');

        $this->cancel($first)->assertOk();

        $this->assertSame($siblingBefore, $second->fresh()->getRawOriginal());
        $this->assertSame($siblingPaymentBefore, $second->payment->fresh()->getRawOriginal());
        $this->assertSame(18, $second->orderItems->sole()->variant->fresh()->stock_quantity);
        $this->assertSame(1, $voucher->fresh()->times_used);
        $this->assertSame(OrderStatus::Preparing, $first->order->fresh()->status);
        Notification::assertNotSentTo($second->seller, BuyerOrderCancelled::class);
        $this->cancel($second)->assertOk();
        $this->assertSame(OrderStatus::Cancelled, $first->order->fresh()->status);
        $this->assertDatabaseCount('inventory_transactions', 2);
    }

    public function test_unauthorized_and_wrong_parent_requests_cannot_change_inventory_or_voucher_usage(): void
    {
        Notification::fake();
        $parcel = $this->parcel();
        $voucher = Voucher::factory()->create(['times_used' => 1]);
        $parcel->forceFill(['voucher_id' => $voucher->id])->save();
        $this->cancel($parcel)->assertUnauthorized();
        $this->actingAs(Buyer::factory()->create(), 'buyer');
        $this->cancel($parcel)->assertNotFound();
        $this->actingAs($parcel->order->buyer, 'buyer');
        $wrongParent = Order::factory()->create(['buyer_id' => $parcel->order->buyer_id]);
        $this->postJson(route('buyer.orders.cancelSellerOrder', [$wrongParent, $parcel]), ['reason' => 'Wrong order'])->assertNotFound();
        $this->assertSame(OrderStatus::Confirmed, $parcel->fresh()->status);
        $this->assertSame(18, $parcel->orderItems->sole()->variant->fresh()->stock_quantity);
        $this->assertSame(PaymentStatus::Pending, $parcel->payment->fresh()->status);
        $this->assertSame(1, $voucher->fresh()->times_used);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('status_histories', 0);
        Notification::assertNothingSent();
    }

    public function test_missing_or_whitespace_reason_is_rejected_without_changes(): void
    {
        $parcel = $this->parcel();
        $this->actingAs($parcel->order->buyer, 'buyer');
        $this->cancel($parcel, '   ')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(OrderStatus::Confirmed, $parcel->fresh()->status);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_cod_save_failure_rolls_back_cancellation_inventory_and_history(): void
    {
        Notification::fake();
        $parcel = $this->parcel();
        Event::listen('eloquent.saving: '.Payment::class, fn () => throw new \RuntimeException('COD write failed'));
        try {
            app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::Cancelled,
                actor: OrderActor::Buyer, actorId: $parcel->order->buyer_id, note: 'Buyer cancelled: Changed my mind');
            $this->fail('The failed COD update must abort cancellation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('COD write failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.saving: '.Payment::class);
        }
        $this->assertSame(OrderStatus::Confirmed, $parcel->fresh()->status);
        $this->assertSame(18, $parcel->orderItems->sole()->variant->fresh()->stock_quantity);
        $this->assertSame(PaymentStatus::Pending, $parcel->payment->fresh()->status);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('status_histories', 0);
        Notification::assertNothingSent();
    }

    public function test_a6_seller_can_cancel_ready_but_cannot_cancel_after_pickup(): void
    {
        $ready = $this->parcel(OrderStatus::ReadyForPickup);
        $this->actingAs($ready->seller, 'seller')->get(route('seller.orders.show', $ready))
            ->assertOk()->assertSee('action="'.route('seller.orders.decline', $ready).'"', false);
        $this->post(route('seller.orders.decline', $ready), ['reason' => 'Cannot fulfill'])
            ->assertRedirect()->assertSessionHas('declined');
        $this->assertSame(OrderStatus::Cancelled, $ready->fresh()->status);
        $this->assertSame(20, $ready->orderItems->sole()->variant->fresh()->stock_quantity);
        $picked = $this->parcel(OrderStatus::PickedUp);
        $this->actingAs($picked->seller, 'seller')->post(route('seller.orders.decline', $picked), ['reason' => 'Too late to cancel'])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame(OrderStatus::PickedUp, $picked->fresh()->status);
        $this->assertSame(18, $picked->orderItems->sole()->variant->fresh()->stock_quantity);
    }
}
