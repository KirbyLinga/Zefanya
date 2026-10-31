<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Enums\VoucherType;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ShopVoucherOffersTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();

        $this->buyer = Buyer::factory()->create();
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->product = Product::factory()->create(['seller_id' => $this->seller->id, 'status' => 'active', 'price' => 1000.00, 'stock_quantity' => 10]);
        ProductVariant::query()->where('product_id', $this->product->id)->update(['price' => 1000.00, 'stock_quantity' => 10, 'is_available' => true]);
    }

    /** @param array<string, mixed> $attributes */
    private function offer(array $attributes = []): Voucher
    {
        return Voucher::factory()->shop($this->seller)->create($attributes + [
            'code' => 'OFFER1',
            'funding_source' => Voucher::FUNDING_SELLER,
            'is_claimable' => true,
            'type' => VoucherType::Percent,
            'value' => 10,
            'max_discount' => 5000,
            'expires_at' => now()->addDay(),
        ]);
    }

    private function storePage(): TestResponse
    {
        return $this->get(route('buyer.stores.show', ['seller' => $this->seller]))->assertOk();
    }

    private function cartLine(): CartItem
    {
        return CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'variant_id' => ProductVariant::query()->where('product_id', $this->product->id)->value('id'),
            'quantity' => 1,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function shipment(array $attributes = [], ?Order $order = null): SellerOrder
    {
        $order ??= Order::factory()->create(['buyer_id' => $this->buyer->id, 'status' => OrderStatus::Placed]);

        return SellerOrder::factory()->create($attributes + [
            'order_id' => $order->id,
            'seller_id' => $this->seller->id,
            'status' => OrderStatus::Placed,
        ]);
    }

    private function buyerCancels(SellerOrder $shipment): void
    {
        $this->actingAs($this->buyer, 'buyer')
            ->postJson(route('buyer.orders.cancelSellerOrder', [$shipment->order_id, $shipment]), ['reason' => 'Changed my mind'])
            ->assertOk();
    }

    public function test_store_product_and_cart_pages_list_claimable_vouchers_with_terms(): void
    {
        $this->offer(['min_spend' => 20000]);
        $terms = '10% off, up to ₱50.00 · Min. spend ₱200.00';

        $this->storePage()->assertSee('OFFER1')->assertSee($terms);
        $this->get(route('buyer.products.show', $this->product))->assertOk()->assertSee('OFFER1')->assertSee($terms);

        $this->cartLine();
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.cart.index'))
            ->assertOk()
            ->assertSee('OFFER1')
            ->assertSee('data-code="OFFER1"', false);
    }

    public function test_only_vouchers_a_buyer_could_use_now_are_listed(): void
    {
        $this->offer(['code' => 'SHOWN1']);
        $this->offer(['code' => 'CODEONLY', 'is_claimable' => false]);
        $this->offer(['code' => 'INACTIVE', 'is_active' => false]);
        $this->offer(['code' => 'NOTYET', 'starts_at' => now()->addDay()]);
        $this->offer(['code' => 'EXPIRED', 'expires_at' => now()->subMinute()]);
        $this->offer(['code' => 'USEDUP', 'usage_limit' => 1, 'times_used' => 1]);
        Voucher::factory()->shop(Seller::factory()->create(['status' => 'approved']))->create(['code' => 'OTHERSHOP', 'is_claimable' => true]);
        Voucher::factory()->create(['code' => 'PLATFORM1', 'is_claimable' => true]);

        $this->assertSame(['SHOWN1'], Voucher::offeredBy([$this->seller->id])->pluck('code')->all());
        $this->storePage()->assertSee('SHOWN1')->assertDontSee('CODEONLY')->assertDontSee('EXPIRED');
    }

    public function test_a_buyer_at_their_limit_stops_seeing_the_voucher_but_others_still_do(): void
    {
        $voucher = $this->offer(['per_buyer_limit' => 1]);
        $this->shipment(['shop_voucher_id' => $voucher->id]);

        $this->assertSame([], Voucher::offeredBy([$this->seller->id], $this->buyer->id)->pluck('code')->all());
        $this->assertSame(['OFFER1'], Voucher::offeredBy([$this->seller->id], Buyer::factory()->create()->id)->pluck('code')->all());
        $this->assertSame(['OFFER1'], Voucher::offeredBy([$this->seller->id])->pluck('code')->all());
    }

    public function test_product_page_hides_a_voucher_limited_to_other_products(): void
    {
        $other = Product::factory()->create(['seller_id' => $this->seller->id, 'status' => 'active']);
        Voucher::factory()->shop($this->seller)->forProducts([$other])->create(['code' => 'OTHERITEM', 'is_claimable' => true, 'type' => VoucherType::Fixed, 'value' => 1000]);
        Voucher::factory()->shop($this->seller)->forProducts([$this->product])->create(['code' => 'THISITEM', 'is_claimable' => true, 'type' => VoucherType::Fixed, 'value' => 1000]);

        $this->get(route('buyer.products.show', $this->product))->assertOk()->assertSee('THISITEM')->assertDontSee('OTHERITEM');
        $this->storePage()->assertSee('THISITEM')->assertSee('OTHERITEM')->assertSee('Selected products');
    }

    public function test_pages_show_nothing_when_there_are_no_offers(): void
    {
        $this->storePage()->assertDontSee('Shop vouchers');
        $this->get(route('buyer.products.show', $this->product))->assertOk()->assertDontSee('Shop vouchers');
    }

    public function test_a_listed_voucher_applies_in_the_cart(): void
    {
        $this->offer();
        $item = $this->cartLine();

        $this->actingAs($this->buyer, 'buyer')
            ->postJson(route('buyer.cart.voucher.apply'), ['code' => 'OFFER1', 'selected_ids' => [$item->id]])
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('discount_raw', 5000);
    }

    public function test_buyer_cancel_returns_a_shop_voucher(): void
    {
        $voucher = $this->offer(['times_used' => 2]);

        $this->buyerCancels($this->shipment(['shop_voucher_id' => $voucher->id]));

        $this->assertSame(1, $voucher->fresh()->times_used);
    }

    public function test_cancelling_a_code_only_voucher_puts_it_in_the_wallet_ready_to_use(): void
    {
        $voucher = $this->offer(['is_claimable' => false, 'times_used' => 1, 'per_buyer_limit' => 1]);
        $shipment = $this->shipment(['shop_voucher_id' => $voucher->id]);
        $this->assertSame(1, $voucher->usesByBuyer($this->buyer->id));
        $this->assertSame(0, $voucher->claims()->count());

        $this->buyerCancels($shipment);

        $this->assertSame(0, $voucher->fresh()->times_used);
        $this->assertSame(0, $voucher->usesByBuyer($this->buyer->id));
        $this->assertDatabaseHas('voucher_claims', ['voucher_id' => $voucher->id, 'buyer_id' => $this->buyer->id]);
        $this->get(route('buyer.vouchers.index'))->assertOk()->assertSee('OFFER1')->assertSee('Ready to use');
        $this->postJson(route('buyer.orders.cancelSellerOrder', [$shipment->order_id, $shipment]), ['reason' => 'Again'])
            ->assertStatus(422);
        $this->assertSame(1, $voucher->claims()->count());
        $this->assertSame(0, $voucher->fresh()->times_used);
    }

    public function test_buyer_cancel_returns_a_shared_voucher_only_after_the_last_shipment(): void
    {
        $platform = Voucher::factory()->create(['code' => 'PLAT1', 'times_used' => 5]);
        $first = $this->shipment(['platform_voucher_id' => $platform->id]);
        $second = $this->shipment(['platform_voucher_id' => $platform->id, 'seller_id' => Seller::factory()->create(['status' => 'approved'])->id], $first->order);

        $this->buyerCancels($first);
        $this->assertSame(5, $platform->fresh()->times_used);
        $this->assertSame(0, $platform->claims()->count());

        $this->buyerCancels($second);
        $this->assertSame(4, $platform->fresh()->times_used);
        $this->assertDatabaseHas('voucher_claims', ['voucher_id' => $platform->id, 'buyer_id' => $this->buyer->id]);
    }

    public function test_seller_then_buyer_cancels_return_a_shared_voucher_exactly_once(): void
    {
        $platform = Voucher::factory()->create(['code' => 'PLAT1', 'times_used' => 5]);
        $first = $this->shipment(['platform_voucher_id' => $platform->id]);
        $second = $this->shipment(['platform_voucher_id' => $platform->id, 'seller_id' => Seller::factory()->create(['status' => 'approved'])->id], $first->order);

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.orders.decline', $first), ['reason' => 'Out of materials'])
            ->assertSessionHas('declined');
        $this->assertSame(5, $platform->fresh()->times_used);

        $this->buyerCancels($second);
        $this->assertSame(4, $platform->fresh()->times_used);
    }

    public function test_failed_pickup_cancel_returns_the_voucher(): void
    {
        $voucher = $this->offer(['times_used' => 1]);
        $shipment = $this->shipment(['shop_voucher_id' => $voucher->id, 'status' => OrderStatus::ReadyForPickup]);

        (new SellerOrderStatusService)->transition($shipment, OrderStatus::Cancelled, actor: OrderActor::Rider, actorId: 1);

        $this->assertSame(0, $voucher->fresh()->times_used);
    }

    public function test_replayed_cancel_returns_the_voucher_once_and_never_below_zero(): void
    {
        $voucher = $this->offer(['times_used' => 1]);
        $shipment = $this->shipment(['shop_voucher_id' => $voucher->id]);
        $service = new SellerOrderStatusService;

        foreach ([$shipment->fresh(), $shipment] as $copy) {
            $service->transition($copy, OrderStatus::Cancelled, actor: OrderActor::Buyer, actorId: $this->buyer->id, expectedFrom: OrderStatus::Placed);
        }

        $this->assertSame(0, $voucher->fresh()->times_used);
    }

    public function test_a_returned_voucher_can_be_used_again_up_to_its_limit(): void
    {
        $voucher = $this->offer(['usage_limit' => 1, 'times_used' => 1]);
        $this->assertSame('This voucher has reached its usage limit.', $voucher->rejectionReason(100000));

        $this->buyerCancels($this->shipment(['shop_voucher_id' => $voucher->id]));

        $this->assertNull($voucher->fresh()->rejectionReason(100000));
        $this->assertSame(['OFFER1'], Voucher::offeredBy([$this->seller->id], $this->buyer->id)->pluck('code')->all());
    }
}
