<?php

namespace Tests\Feature\Order;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Notifications\Logistics\PickupRequested;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class Rule02OrderStatusFlowTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    public function test_sel_ord_002_requires_separate_accept_pack_and_ready_actions(): void
    {
        Notification::fake();
        $seller = Seller::factory()->create();
        $buyer = Buyer::factory()->create();
        $provider = $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        $parent = Order::factory()->create(['buyer_id' => $buyer->id]);
        $parcel = SellerOrder::factory()->create(['seller_id' => $seller->id, 'order_id' => $parent->id]);
        $parcel->forceFill(['carrier_provider_id' => $provider->id])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $parcel->id]);
        $paymentBefore = $payment->fresh()->getRawOriginal();
        $ledgerBefore = InventoryTransaction::count();

        foreach (['accept' => OrderStatus::Confirmed, 'pack' => OrderStatus::Preparing, 'markReady' => OrderStatus::ReadyForPickup] as $action => $status) {
            $this->actingAs($seller, 'seller')->post(route('seller.orders.'.$action, $parcel))
                ->assertRedirect()->assertSessionMissing('error');
            $this->assertSame($status, $parcel->fresh()->status);
            $this->assertSame($status, $parent->fresh()->status);
            // Retrying the same action must not add a tracking event.
            $this->post(route('seller.orders.'.$action, $parcel))->assertRedirect();
        }

        $this->assertSame(['CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP'], $parcel->statusHistories()->get()->map(fn ($row) => $row->to_status->value)->all());
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertSame($ledgerBefore, InventoryTransaction::count());
        $this->assertNull($parcel->fresh()->courier_id);
        Notification::assertSentToTimes($provider, PickupRequested::class, 1);
    }

    public static function invalidActions(): array
    {
        return [
            'new to ready' => [OrderStatus::Placed, 'markReady'],
            'new to packing' => [OrderStatus::Placed, 'pack'],
            'accepted to ready' => [OrderStatus::Confirmed, 'markReady'],
            'packing to accepted' => [OrderStatus::Preparing, 'accept'],
            'picked up to ready' => [OrderStatus::PickedUp, 'markReady'],
        ];
    }

    #[DataProvider('invalidActions')]
    public function test_sel_ord_003_direct_http_requests_cannot_skip_or_reverse(OrderStatus $from, string $action): void
    {
        Notification::fake();
        $parcel = SellerOrder::factory()->status($from)->create();
        $payment = Payment::factory()->create(['seller_order_id' => $parcel->id]);
        $paymentBefore = $payment->fresh()->getRawOriginal();

        $this->actingAs($parcel->seller, 'seller')->post(route('seller.orders.'.$action, $parcel))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame($from, $parcel->fresh()->status);
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertDatabaseCount('status_histories', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        Notification::assertNothingSent();
    }

    public function test_seller_shortcut_is_rejected_even_without_an_actor(): void
    {
        $parcel = SellerOrder::factory()->create()->fresh();
        try {
            app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::ReadyForPickup);
            $this->fail('The shared graph must reject the shortcut.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(OrderStatus::Placed, $parcel->fresh()->status);
            $this->assertDatabaseCount('status_histories', 0);
        }
    }

    public function test_another_seller_cannot_perform_any_fulfillment_action(): void
    {
        $parcel = SellerOrder::factory()->create()->fresh();
        $this->actingAs(Seller::factory()->create(), 'seller');
        foreach (['accept', 'pack', 'markReady'] as $action) {
            $this->post(route('seller.orders.'.$action, $parcel))->assertForbidden();
        }
        $this->assertSame(OrderStatus::Placed, $parcel->fresh()->status);
        $this->assertDatabaseCount('status_histories', 0);
    }

    public function test_seller_screens_offer_only_the_next_fulfillment_action(): void
    {
        $parcel = SellerOrder::factory()->create()->fresh();
        $this->actingAs($parcel->seller, 'seller');
        foreach ([['accept', 'pack'], ['pack', 'markReady'], ['markReady', 'accept']] as [$next, $hidden]) {
            foreach (['index', 'show'] as $screen) {
                $this->get(route('seller.orders.'.$screen, $screen === 'show' ? $parcel : []))
                    ->assertOk()->assertSee('action="'.route('seller.orders.'.$next, $parcel).'"', false)
                    ->assertDontSee('action="'.route('seller.orders.'.$hidden, $parcel).'"', false);
            }
            $this->post(route('seller.orders.'.$next, $parcel))->assertRedirect();
        }
    }

    public function test_buy_trk_002_tracking_is_once_per_transition_and_to_receive_starts_at_pickup(): void
    {
        $parcel = SellerOrder::factory()->create()->fresh();
        $buyer = $parcel->order->buyer;
        $service = app(SellerOrderStatusService::class);
        foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup] as $to) {
            $parcel = $service->transition($parcel, $to, actor: OrderActor::Seller);
            $service->transition($parcel->fresh(), $to, actor: OrderActor::Seller);
        }
        $this->actingAs($buyer, 'buyer')->get(route('buyer.orders.index', ['tab' => 'to_ship']))
            ->assertOk()->assertViewHas('counts', fn ($counts) => $counts['to_ship'] === 1 && $counts['to_receive'] === 0)
            ->assertSee($parcel->order->reference);
        $this->get(route('buyer.orders.index', ['tab' => 'to_receive']))->assertDontSee($parcel->order->reference);

        $parcel = $service->transition($parcel->fresh(), OrderStatus::PickedUp, actor: OrderActor::Rider);
        $service->transition($parcel->fresh(), OrderStatus::PickedUp, actor: OrderActor::Rider);

        $this->get(route('buyer.orders.index', ['tab' => 'to_receive']))
            ->assertOk()->assertViewHas('counts', fn ($counts) => $counts['to_receive'] === 1 && $counts['to_ship'] === 0)
            ->assertSee($parcel->order->reference);
        $this->get(route('buyer.orders.index', ['tab' => 'to_ship']))->assertDontSee($parcel->order->reference);
        $this->assertSame(['Order created', 'Confirmed', 'Preparing', 'Ready for pickup', 'Picked up'], array_column($parcel->fresh()->trackingEvents(), 'title'));
        $this->assertSame(4, StatusHistory::where('seller_order_id', $parcel->id)->count());
        $this->get(route('buyer.orders.show', $parcel->order))->assertOk()->assertSee('Picked up');
    }

    public function test_buy_pay_001_cod_checkout_appears_directly_in_to_ship(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        $cart = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($buyer, 'buyer')->withSession([CheckoutController::SESSION_KEY => [$cart->id]])
            ->post(route('buyer.checkout.store'), [
                'checkout_token' => CheckoutToken::issue($buyer->id)->token,
                ...$this->checkoutCarrierPayload([$seller]),
            ])->assertRedirect()->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $payment = Payment::query()->sole();
        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertSame(PaymentMethod::Cod, $payment->method);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->get(route('buyer.orders.index', ['tab' => 'to_ship']))->assertOk()->assertSee($order->reference)
            ->assertViewHas('counts', fn ($counts) => $counts['to_ship'] === 1 && $counts['to_receive'] === 0);
    }
}
