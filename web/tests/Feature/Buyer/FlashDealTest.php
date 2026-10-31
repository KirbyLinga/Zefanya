<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Enums\PromotionKind;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\StoreSale;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use App\Services\Shared\OfferPricing;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class FlashDealTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->buyer = Buyer::factory()->create();
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        [$this->product, $this->variant] = $this->productAt(1000.00);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sel_flash_001_to_004_quantity_countdown_split_lines_and_shared_slots(): void
    {
        $sale = StoreSale::factory()->create([
            'seller_id' => $this->seller->id,
            'product_id' => $this->product->id,
            'type' => 'percent',
            'value' => 20,
            'flash_quantity' => 2,
        ]);

        $this->get(route('buyer.home'))
            ->assertOk()
            ->assertSee('Ends in')
            ->assertSee('id="flash-countdown"', false)
            ->assertSee('2 flash left');

        $otherVariant = ProductVariant::factory()->create([
            'product_id' => $this->product->id,
            'price' => 1000,
            'stock_quantity' => 10,
            'is_available' => true,
        ]);
        $first = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'quantity' => 2,
        ]);
        $second = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'variant_id' => $otherVariant->id,
            'quantity' => 2,
        ]);
        $first->load('product.ongoingSale', 'variant');
        $second->load('product.ongoingSale', 'variant');
        app(OfferPricing::class)->bind(collect([$first, $second]));

        $this->assertSame(160000, $first->lineTotalCentavos());
        $this->assertSame(200000, $second->lineTotalCentavos());

        CartItem::query()->where('buyer_id', $this->buyer->id)->delete();
        app(OfferPricing::class)->forget();

        $this->actingAs($this->buyer, 'buyer');
        $line = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'quantity' => 5,
        ]);

        $this->get(route('buyer.cart.index'))
            ->assertOk()
            ->assertSee('₱800.00 × 2 and ₱1,000.00 × 3');

        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);
        session([CheckoutController::SESSION_KEY => [$line->id]]);
        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            ...$this->checkoutCarrierPayload([$this->seller]),
        ])->assertRedirect();

        $items = OrderItem::query()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame(2, $items[0]->quantity);
        $this->assertSame(80000, $items[0]->price_minor);
        $this->assertSame(100000, $items[0]->regular_price_minor);
        $this->assertSame($sale->id, $items[0]->store_sale_id);
        $this->assertSame(3, $items[1]->quantity);
        $this->assertSame(100000, $items[1]->price_minor);
        $this->assertNull($items[1]->store_sale_id);
        $this->assertSame(460000, $items[0]->sellerOrder->subtotal_minor);
        $this->assertSame(5, (int) $this->variant->fresh()->stock_quantity);
    }

    public function test_sel_flash_005_a_sold_out_slot_charges_regular_until_the_order_is_cancelled(): void
    {
        StoreSale::factory()->create([
            'seller_id' => $this->seller->id,
            'product_id' => $this->product->id,
            'type' => 'percent',
            'value' => 20,
            'flash_quantity' => 1,
        ]);

        $this->checkout(1)->assertRedirect();
        $this->checkout(1)->assertRedirect();

        $orders = SellerOrder::query()->orderBy('id')->get();
        $this->assertSame(80000, $orders[0]->orderItems()->sole()->price_minor);
        $this->assertSame(100000, $orders[1]->orderItems()->sole()->price_minor);
        $this->assertNull($orders[1]->orderItems()->sole()->store_sale_id);

        $orders[0]->forceFill(['status' => OrderStatus::Cancelled])->save();
        app(OfferPricing::class)->forget();

        $this->checkout(1)->assertRedirect();
        $released = SellerOrder::query()->latest('id')->firstOrFail();
        $this->assertSame(80000, $released->orderItems()->sole()->price_minor);
    }

    public function test_sel_flash_006_bundle_percentage_discounts_every_member_bought_together(): void
    {
        [$other] = $this->productAt(1000.00, 'Second Scarf');
        SellerPromotion::query()->create([
            'seller_id' => $this->seller->id,
            'kind' => PromotionKind::BundlePercent,
            'name' => 'Scarf pair',
            'percent' => 10,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ])->products()->sync([$this->product->id, $other->id]);

        $this->checkout(1)->assertRedirect();
        $this->assertSame(100000, SellerOrder::query()->latest('id')->firstOrFail()->orderItems()->sole()->price_minor);

        $this->checkoutProducts([
            [$this->product, $this->variant, 1],
            [$other, ProductVariant::query()->where('product_id', $other->id)->firstOrFail(), 1],
        ])->assertRedirect();

        $lines = SellerOrder::query()->latest('id')->firstOrFail()->orderItems()->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame([90000, 90000], $lines->pluck('price_minor')->all());
        $this->assertSame([100000, 100000], $lines->pluck('regular_price_minor')->all());
        $this->assertSame(180000, $lines[0]->sellerOrder->subtotal_minor);
    }

    public function test_sel_flash_007_buy_x_get_y_adds_a_free_line_and_still_takes_stock(): void
    {
        SellerPromotion::query()->create([
            'seller_id' => $this->seller->id,
            'kind' => PromotionKind::BuyXGetY,
            'name' => 'Buy 2 Get 1',
            'buy_quantity' => 2,
            'get_quantity' => 1,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
        ])->products()->sync([$this->product->id]);

        $this->checkout(3)->assertRedirect();

        $lines = OrderItem::query()->orderBy('id')->get();
        $this->assertSame([2, 1], $lines->pluck('quantity')->all());
        $this->assertSame([100000, 0], $lines->pluck('price_minor')->all());
        $this->assertSame(200000, $lines[0]->sellerOrder->subtotal_minor);
        $this->assertSame(7, (int) $this->variant->fresh()->stock_quantity);
    }

    public function test_sel_flash_008_seller_sets_flash_quantity_and_creates_both_deals(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'UTC'));
        $this->actingAs($this->seller, 'seller');
        [$other] = $this->productAt(50.00, 'Bundle Mate');

        $this->post(route('seller.store-sales.store'), [
            'product_id' => $this->product->id,
            'type' => 'percent',
            'percent' => 20,
            'flash_quantity' => 8,
            'starts_at' => '2026-11-02T10:00',
            'ends_at' => '2026-11-03T10:00',
        ])->assertRedirect(route('seller.store-sales.index'))->assertSessionHasNoErrors();

        $this->assertSame(8, StoreSale::query()->sole()->flash_quantity);
        $this->assertSame('20% off · 8 slots', StoreSale::query()->sole()->discountLabel());

        $this->post(route('seller.promotions.store'), [
            'kind' => 'bundle_percent',
            'name' => 'Pair',
            'percent' => 15,
            'product_ids' => [$this->product->id, $other->id],
            'starts_at' => '2026-11-02T10:00',
            'ends_at' => '2026-11-03T10:00',
        ])->assertRedirect(route('seller.promotions.index'))->assertSessionHasNoErrors();

        $this->post(route('seller.promotions.store'), [
            'kind' => 'buy_x_get_y',
            'name' => 'Buy 2 Get 1',
            'buy_quantity' => 2,
            'get_quantity' => 1,
            'product_ids' => [$other->id],
            'starts_at' => '2026-11-02T10:00',
            'ends_at' => '2026-11-03T10:00',
        ])->assertRedirect(route('seller.promotions.index'))->assertSessionHasNoErrors();

        $this->get(route('seller.promotions.index'))
            ->assertOk()
            ->assertSee('Pair')
            ->assertSee('Buy 2 Get 1')
            ->assertSee('15% off the bundle');

        $bundle = SellerPromotion::query()->where('kind', PromotionKind::BundlePercent)->firstOrFail();
        $this->assertSame(15, $bundle->percent);
        $this->assertEqualsCanonicalizing(
            [$this->product->id, $other->id],
            $bundle->products()->pluck('products.id')->all(),
        );
    }

    /** @return array{0: Product, 1: ProductVariant} */
    private function productAt(float $price, string $name = 'Silk Scarf'): array
    {
        $product = Product::factory()->create([
            'seller_id' => $this->seller->id,
            'name' => $name,
            'price' => $price,
            'stock_quantity' => 10,
            'status' => 'active',
        ]);
        $variant = ProductVariant::query()->where('product_id', $product->id)->firstOrFail();
        $variant->forceFill(['price' => $price, 'stock_quantity' => 10, 'is_available' => true])->save();

        return [$product, $variant];
    }

    private function checkout(int $quantity)
    {
        return $this->checkoutProducts([[$this->product, $this->variant, $quantity]]);
    }

    /** @param list<array{0: Product, 1: ProductVariant, 2: int}> $lines */
    private function checkoutProducts(array $lines)
    {
        $ids = [];
        foreach ($lines as [$product, $variant, $quantity]) {
            $ids[] = CartItem::factory()->create([
                'buyer_id' => $this->buyer->id,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'quantity' => $quantity,
            ])->id;
        }

        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);
        $this->actingAs($this->buyer, 'buyer');
        session([CheckoutController::SESSION_KEY => $ids]);

        return $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            ...$this->checkoutCarrierPayload([$this->seller]),
        ]);
    }
}
