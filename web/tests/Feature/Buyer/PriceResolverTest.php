<?php

namespace Tests\Feature\Buyer;

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreSale;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Voucher;
use App\Services\Buyer\CartSummary;
use App\Services\Shared\PriceResolver;
use App\Services\Shared\ResolvedPrice;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class PriceResolverTest extends TestCase
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

        $this->buyer = Buyer::factory()->create([
            'province_name' => 'Metro Manila',
            'municipality_name' => 'Quezon City',
            'barangay_name' => 'Diliman',
            'street' => 'Maple Street',
        ]);
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        [$this->product, $this->variant] = $this->productAt(1000.00);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    /** @param array<string, mixed> $attributes */
    private function sale(array $attributes = [], ?Product $product = null): StoreSale
    {
        return StoreSale::factory()->create($attributes + [
            'seller_id' => $this->seller->id,
            'product_id' => ($product ?? $this->product)->id,
        ]);
    }

    private function resolved(): ResolvedPrice
    {
        return app(PriceResolver::class)->forVariant($this->product->fresh(), $this->variant->fresh());
    }

    private function checkout(int $quantity = 1, ?array $extra = null): TestResponse
    {
        $item = CartItem::factory()->create([
            'buyer_id' => $this->buyer->id,
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'quantity' => $quantity,
        ]);
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);
        $this->actingAs($this->buyer, 'buyer');
        session([CheckoutController::SESSION_KEY => [$item->id]]);

        return $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            ...$this->checkoutCarrierPayload([$this->seller]),
            ...($extra ?? []),
        ]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: int, 2: string|null}> */
    public static function saleShapes(): array
    {
        return [
            'percent' => [['type' => 'percent', 'value' => 20], 80000, '-20%'],
            'fixed price' => [['type' => 'fixed_price', 'value' => 75000], 75000, '-25%'],
            'percent rounds the saving half up' => [['type' => 'percent', 'value' => 33], 67000, '-33%'],
            'fixed price above regular never raises it' => [['type' => 'fixed_price', 'value' => 150000], 100000, null],
        ];
    }

    /** @param array<string, mixed> $shape */
    #[DataProvider('saleShapes')]
    public function test_sale_shapes_resolve_to_one_price(array $shape, int $expected, ?string $badge): void
    {
        $this->sale($shape);

        $this->assertSame($expected, $this->resolved()->unitCentavos());
        $this->assertSame(100000, $this->resolved()->regularUnitCentavos());
        $this->assertSame($badge, $this->resolved()->badge());
    }

    public function test_percent_rounds_to_the_centavo_without_floats(): void
    {
        [$product, $variant] = $this->productAt(19.99, 'Cheap Tie');
        $this->sale(['type' => 'percent', 'value' => 15], $product);

        // 15% of 1999 is 299.85, so the saving is 300 centavos.
        $this->assertSame(1699, app(PriceResolver::class)->forVariant($product, $variant)->unitCentavos());
    }

    public function test_only_a_running_sale_counts(): void
    {
        $this->sale(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'value' => 50]);
        $this->assertSame(100000, $this->resolved()->unitCentavos());

        Carbon::setTestNow(now()->addDay());
        $this->assertSame(50000, $this->resolved()->unitCentavos());

        Carbon::setTestNow(now()->addDay());
        $this->assertSame(100000, $this->resolved()->unitCentavos());
    }

    public function test_variant_sale_only_discounts_that_variant_and_the_range_reflects_it(): void
    {
        $other = ProductVariant::factory()->create(['product_id' => $this->product->id, 'price' => 1200.00, 'stock_quantity' => 5, 'is_available' => true]);
        $this->sale(['variant_id' => $this->variant->id, 'type' => 'percent', 'value' => 50]);
        $product = $this->product->fresh();
        $resolver = app(PriceResolver::class);

        $this->assertSame(50000, $resolver->forVariant($product, $this->variant)->unitCentavos());
        $this->assertSame(120000, $resolver->forVariant($product, $other)->unitCentavos());

        $range = $product->buyerPrice();
        $this->assertSame("\u{20B1}500.00\u{2013}\u{20B1}1,200.00", $range->formatted());
        $this->assertSame("\u{20B1}1,000.00\u{2013}\u{20B1}1,200.00", $range->formattedRegular());
        $this->assertSame('Up to -50%', $range->badge());
    }

    public function test_range_ignores_a_sold_out_variant(): void
    {
        ProductVariant::factory()->create(['product_id' => $this->product->id, 'price' => 5.00, 'stock_quantity' => 0, 'is_available' => true]);
        $this->sale(['type' => 'percent', 'value' => 10]);

        $this->assertSame("\u{20B1}900.00", $this->product->fresh()->formattedPriceRange());
    }

    public function test_product_page_card_search_and_cart_all_show_the_same_price(): void
    {
        $this->sale(['type' => 'percent', 'value' => 20]);
        $this->actingAs($this->buyer, 'buyer');

        $price = "\u{20B1}800.00";
        $regular = "\u{20B1}1,000.00";

        $this->get(route('buyer.products.show', $this->product))->assertOk()->assertSee($price)->assertSee($regular)->assertSee('-20%');

        $card = Product::query()->withBuyerCardData()->with('seller')->findOrFail($this->product->id);
        $rendered = (string) $this->view('Components.buyer.product-card', ['product' => $card, 'wishlistIds' => collect()]);
        $this->assertStringContainsString($price, $rendered);
        $this->assertStringContainsString($regular, $rendered);
        $this->assertStringContainsString('-20%', $rendered);

        $this->get(route('buyer.products.index'))->assertOk()->assertSee($price);
        $this->get(route('buyer.search.index', ['q' => 'Silk']))->assertOk()->assertSee($price);

        $this->postJson(route('buyer.cart.add'), ['product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1])
            ->assertOk()
            ->assertJsonPath('product_price', $price);

        $item = CartItem::query()->where('buyer_id', $this->buyer->id)->sole();
        $this->assertSame(80000, $item->unitPriceCentavos());
        $this->get(route('buyer.cart.index'))->assertOk()->assertSee('data-unit-centavos="80000"', false);
    }

    public function test_product_page_sends_resolved_prices_to_the_variant_picker(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'status' => 'active']);
        $first = ProductVariant::query()->where('product_id', $product->id)->firstOrFail();
        $first->forceFill(['price' => 100.00, 'stock_quantity' => 3])->save();
        ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 200.00, 'stock_quantity' => 3, 'is_available' => true]);
        $this->sale(['variant_id' => $first->id, 'type' => 'percent', 'value' => 10], $product);

        $variants = $this->get(route('buyer.products.show', $product))->assertOk()->viewData('variantData');

        $this->assertSame("\u{20B1}90.00", $variants[0]['price']);
        $this->assertSame("\u{20B1}100.00", $variants[0]['regular_price']);
        $this->assertSame('-10%', $variants[0]['badge']);
        $this->assertSame("\u{20B1}200.00", $variants[1]['price']);
        $this->assertNull($variants[1]['regular_price']);
    }

    public function test_cart_and_order_totals_use_the_sale_price_and_the_snapshot_survives_the_sale(): void
    {
        $sale = $this->sale(['type' => 'fixed_price', 'value' => 60000]);

        $this->checkout(2)->assertRedirect();

        $line = OrderItem::query()->sole();
        $this->assertSame(60000, $line->price_minor);
        $this->assertSame(120000, $line->sellerOrder->subtotal_minor);
        $this->assertSame(10 - 2, (int) $this->variant->fresh()->stock_quantity);
        $this->assertSame(120000 + $line->sellerOrder->shipping_fee_minor, $line->sellerOrder->payment->amount_minor);

        $sale->forceFill(['ends_at' => now()->addSecond()])->save();
        $this->assertSame(100000, $line->regular_price_minor);
        $this->assertSame($sale->id, $line->store_sale_id);

        $sale->delete();
        $this->variant->forceFill(['price' => 5000.00])->save();

        $line->refresh();
        $this->assertSame(60000, $line->price_minor);
        $this->assertSame(100000, $line->regular_price_minor);
        $this->assertNull($line->store_sale_id);
    }

    public function test_order_without_a_sale_records_no_sale_on_the_snapshot(): void
    {
        $this->sale(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

        $this->checkout()->assertRedirect();

        $line = OrderItem::query()->sole();
        $this->assertSame(100000, $line->price_minor);
        $this->assertSame(100000, $line->regular_price_minor);
        $this->assertNull($line->store_sale_id);
    }

    public function test_cart_and_checkout_show_the_regular_price_beside_the_sale_price(): void
    {
        $this->sale(['type' => 'percent', 'value' => 20]);
        $item = CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1]);
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);
        $this->actingAs($this->buyer, 'buyer');

        $this->get(route('buyer.cart.index'))->assertOk()->assertSeeInOrder(["\u{20B1}1,000.00", "\u{20B1}800.00 each"]);
        $this->get(route('buyer.checkout.index', ['items' => [$item->id]]))->assertOk()->assertSeeInOrder(["Unit Price: \u{20B1}800.00", "\u{20B1}1,000.00"]);
    }

    public function test_flash_sale_lists_only_real_discounts_and_hides_when_there_are_none(): void
    {
        $this->get(route('buyer.home'))->assertOk()->assertDontSee('id="flash-sale"', false);

        [$onSale] = $this->productAt(500.00, 'Discounted Bag');
        $this->sale(['type' => 'percent', 'value' => 20], $onSale);
        [$noDiscount] = $this->productAt(500.00, 'Fixed Above Regular');
        $this->sale(['type' => 'fixed_price', 'value' => 90000], $noDiscount);

        $flash = $this->get(route('buyer.home'))->assertOk()->assertSee('id="flash-sale"', false)->viewData('flashProducts');

        $this->assertSame([$onSale->id], $flash->pluck('id')->all());
    }

    public function test_voucher_applies_to_the_sale_price(): void
    {
        $this->sale(['type' => 'percent', 'value' => 50]);
        $voucher = Voucher::factory()->percent(10)->create(['code' => 'TEN', 'min_spend' => 40000]);

        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);
        $item = CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1]);
        $this->actingAs($this->buyer, 'buyer');
        session([CheckoutController::SESSION_KEY => [$item->id], 'cart_voucher' => 'TEN']);

        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            ...$this->checkoutCarrierPayload([$this->seller]),
        ])->assertRedirect();

        $sellerOrder = OrderItem::query()->sole()->sellerOrder;
        $this->assertSame(50000, $sellerOrder->subtotal_minor);
        $this->assertSame(5000, $sellerOrder->order->discount_minor);
        $this->assertSame(1, $voucher->fresh()->times_used);
    }

    public function test_voucher_minimum_spend_is_checked_against_the_sale_price(): void
    {
        $this->sale(['type' => 'percent', 'value' => 50]);
        Voucher::factory()->percent(10)->create(['code' => 'BIGSPEND', 'min_spend' => 80000]);

        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);
        $item = CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1]);
        $this->actingAs($this->buyer, 'buyer');
        session([CheckoutController::SESSION_KEY => [$item->id], 'cart_voucher' => 'BIGSPEND']);

        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            ...$this->checkoutCarrierPayload([$this->seller]),
        ])->assertSessionHas('error', fn (string $message) => str_contains($message, 'minimum spend'));

        $this->assertSame(0, OrderItem::query()->count());
    }

    public function test_order_stops_when_a_sale_ended_while_the_buyer_was_on_checkout(): void
    {
        $sale = $this->sale(['type' => 'percent', 'value' => 50]);
        $item = CartItem::factory()->make(['buyer_id' => $this->buyer->id, 'product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1]);
        $item->id = CartItem::query()->max('id') + 1;
        $reviewed = (new CartSummary(collect([$item->load('product', 'variant')])))->priceFingerprint();

        $sale->delete();

        $response = $this->checkout(1, ['price_fingerprint' => $reviewed])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'price changed'));
        $this->assertStringContainsString('/buyer/checkout', (string) $response->headers->get('Location'));

        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(10, (int) $this->variant->fresh()->stock_quantity);
    }

    public function test_checkout_page_carries_a_fingerprint_that_places_the_order(): void
    {
        $this->sale(['type' => 'percent', 'value' => 50]);
        $item = CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1]);
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);
        $this->actingAs($this->buyer, 'buyer');

        $page = $this->get(route('buyer.checkout.index', ['items' => [$item->id]]))->assertOk();
        $this->assertSame(1, preg_match('/name="price_fingerprint" value="([0-9a-f]{64})"/', $page->getContent(), $match));

        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($this->buyer->id)->token,
            'price_fingerprint' => $match[1],
            ...$this->checkoutCarrierPayload([$this->seller]),
        ])->assertRedirect();

        $this->assertSame(50000, OrderItem::query()->sole()->price_minor);
    }

    public function test_price_sort_and_filters_use_the_sale_price(): void
    {
        [$cheaper] = $this->productAt(700.00, 'Plain Belt');
        $this->sale(['type' => 'percent', 'value' => 50]); // Silk Scarf is now 500, below Plain Belt.

        $this->assertSame(
            [$this->product->id, $cheaper->id],
            $this->get(route('buyer.products.index', ['sort' => 'price_asc']))->viewData('products')->pluck('id')->all(),
        );

        $this->assertSame(
            [$this->product->id],
            $this->get(route('buyer.products.index', ['max_price' => 600]))->viewData('products')->pluck('id')->all(),
        );

        $this->assertSame(
            [$cheaper->id],
            $this->get(route('buyer.products.index', ['min_price' => 600]))->viewData('products')->pluck('id')->all(),
        );
    }

    public function test_sql_sort_price_matches_the_resolver_for_every_sale_shape(): void
    {
        $shapes = [
            ['type' => 'percent', 'value' => 15],
            ['type' => 'fixed_price', 'value' => 12345],
            ['type' => 'fixed_price', 'value' => 999999],
        ];

        foreach ($shapes as $i => $shape) {
            [$product] = $this->productAt(199.99, "Item {$i}");
            $this->sale($shape, $product);
            $pesos = $product->fresh()->buyerPrice()->unitCentavos() / 100;

            $found = fn (array $query) => $this->get(route('buyer.products.index', $query))->viewData('products')->pluck('id');

            $this->assertContains($product->id, $found(['min_price' => $pesos, 'max_price' => $pesos]), json_encode($shape));
        }
    }

    public function test_seller_catalog_keeps_the_regular_price_and_stored_prices_do_not_change(): void
    {
        $this->sale(['type' => 'percent', 'value' => 50]);

        $this->assertSame("\u{20B1}1,000.00", $this->product->fresh()->load('variants')->formattedSellerPriceRange());
        $this->assertSame('1000.00', (string) $this->variant->fresh()->price);
        $this->assertSame('1000.00', (string) $this->product->fresh()->price);
    }

    public function test_cards_do_not_query_per_product(): void
    {
        foreach (range(1, 6) as $i) {
            [$product] = $this->productAt(100.00 + $i, "Card {$i}");
            $this->sale(['type' => 'percent', 'value' => 10], $product);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $products = Product::query()->withBuyerCardData()->with('seller')->get();
        $before = count(DB::getQueryLog());

        $products->each(fn (Product $product) => $product->buyerPrice()?->formatted());

        $this->assertSame($before, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_home_page_has_no_invented_discount_badges(): void
    {
        $this->get(route('buyer.home'))
            ->assertOk()
            ->assertDontSee('>40%<', false)
            ->assertDontSee('>28%<', false);
    }
}
