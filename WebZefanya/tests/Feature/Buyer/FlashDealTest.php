<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
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

    public function test_on_sale_filter_drops_a_sold_out_flash_sale_until_the_order_is_cancelled(): void
    {
        StoreSale::factory()->create([
            'seller_id' => $this->seller->id,
            'product_id' => $this->product->id,
            'type' => 'percent',
            'value' => 20,
            'flash_quantity' => 1,
        ]);
        $onSale = fn () => $this->get(route('buyer.products.index', ['on_sale' => 1]))->assertOk()->viewData('products')->pluck('id')->all();

        $this->assertSame([$this->product->id], $onSale());

        $this->checkout(1)->assertRedirect();
        $this->assertSame([], $onSale());

        SellerOrder::query()->sole()->forceFill(['status' => OrderStatus::Cancelled])->save();
        $this->assertSame([$this->product->id], $onSale());
    }

    public function test_price_sort_and_filters_use_the_regular_price_once_flash_slots_sell_out(): void
    {
        [$cheaper] = $this->productAt(900.00, 'Plain Belt');
        StoreSale::factory()->create([
            'seller_id' => $this->seller->id,
            'product_id' => $this->product->id,
            'type' => 'percent',
            'value' => 20,
            'flash_quantity' => 1,
        ]);
        $ids = fn (array $query) => $this->get(route('buyer.products.index', $query))->assertOk()->viewData('products')->pluck('id')->all();
        $assertPricedAtSale = function () use ($ids, $cheaper): void {
            $this->assertSame([$this->product->id, $cheaper->id], $ids(['sort' => 'price_asc']));
            $this->assertSame([$this->product->id], $ids(['min_price' => 800, 'max_price' => 800]));
            $this->assertSame([], $ids(['min_price' => 1000, 'max_price' => 1000]));
        };

        $assertPricedAtSale();

        $this->checkout(1)->assertRedirect();
        $this->assertSame("\u{20B1}1,000.00", $this->product->fresh()->buyerPrice()->formatted());
        $this->assertSame([$cheaper->id, $this->product->id], $ids(['sort' => 'price_asc']));
        $this->assertSame([$cheaper->id, $this->product->id], array_reverse($ids(['sort' => 'price_desc'])));
        $this->assertSame([], $ids(['min_price' => 800, 'max_price' => 800]));
        $this->assertSame([$this->product->id], $ids(['min_price' => 1000, 'max_price' => 1000]));

        SellerOrder::query()->sole()->forceFill(['status' => OrderStatus::Cancelled])->save();
        $assertPricedAtSale();
    }

    public function test_sel_flash_008_seller_sets_flash_quantity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'UTC'));
        $this->actingAs($this->seller, 'seller');

        $this->post(route('seller.store-sales.store'), [
            'product_id' => $this->product->id,
            'type' => 'percent',
            'percent' => 20,
            'flash_quantity' => 8,
            'starts_at' => '2026-11-02T10:00',
            'ends_at' => '2026-11-03T10:00',
        ])->assertRedirect(route('seller.discounts.index'))->assertSessionHasNoErrors();

        $this->assertSame(8, StoreSale::query()->sole()->flash_quantity);
        $this->assertSame('20% off · 8 slots', StoreSale::query()->sole()->discountLabel());
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
