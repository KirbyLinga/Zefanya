<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductPagePurchaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_buy_pdp_001_variation_page_asks_the_buyer_to_select_a_variation(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 0, 'status' => 'active']);
        app(VariantSyncService::class)->sync($product,
            [['name' => 'Color', 'values' => ['Black']], ['name' => 'Size', 'values' => ['M']]],
            [['values' => ['Black', 'M'], 'price' => 100, 'stock_quantity' => 8, 'is_available' => true]],
            true);

        $this->get(route('buyer.products.show', $product))
            ->assertOk()
            ->assertSee('Please select a variation')
            ->assertSee('data-selection-message="Please select a variation"', false);
    }

    public function test_buy_pdp_004_quantity_is_kept_between_one_and_the_stock(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 40, 'status' => 'active', 'price' => 10]);
        ProductVariant::query()->where('product_id', $product->id)->update(['stock_quantity' => 40, 'is_available' => true]);

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.products.show', $product))
            ->assertOk()
            ->assertSee('max="40"', false);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 45])
            ->assertOk();

        $this->assertSame(40, CartItem::query()->where('buyer_id', $buyer->id)->value('quantity'));

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 0])
            ->assertUnprocessable();
    }

    public function test_earlier_orders_do_not_reduce_what_the_buyer_can_add(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'stock_quantity' => 39,
            'status' => 'active',
        ]);
        ProductVariant::query()->where('product_id', $product->id)->update(['stock_quantity' => 39, 'is_available' => true]);
        $this->purchase($buyer, $seller, $product, 2);

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.products.show', $product))
            ->assertOk()
            ->assertSee('max="39"', false);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 39])
            ->assertOk();

        $this->assertSame(39, CartItem::query()->where('buyer_id', $buyer->id)->value('quantity'));
    }

    public function test_units_in_the_cart_do_not_reduce_the_quantity_limit_and_the_cart_line_stays_within_stock(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 39, 'status' => 'active']);
        ProductVariant::query()->where('product_id', $product->id)->update(['stock_quantity' => 39, 'is_available' => true]);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 30])
            ->assertOk();

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.products.show', $product))
            ->assertOk()
            ->assertSee('max="39"', false)
            ->assertSee('39 unit(s) available');

        $this->assertSame(39, ProductVariant::query()->where('product_id', $product->id)->value('stock_quantity'));

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 30])
            ->assertOk()
            ->assertJsonPath('quantity_warning', 'Only 39 available.');

        $this->assertSame(39, CartItem::query()->where('buyer_id', $buyer->id)->value('quantity'));
    }

    public function test_each_variant_is_limited_by_its_own_stock(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 0, 'status' => 'active']);
        app(VariantSyncService::class)->sync($product,
            [['name' => 'Color', 'values' => ['Black', 'White']]],
            [
                ['values' => ['Black'], 'price' => 100, 'stock_quantity' => 30, 'is_available' => true],
                ['values' => ['White'], 'price' => 100, 'stock_quantity' => 30, 'is_available' => true],
            ],
            true);
        $black = ProductVariant::query()->where('product_id', $product->id)->orderBy('id')->firstOrFail();
        $white = ProductVariant::query()->where('product_id', $product->id)->orderByDesc('id')->firstOrFail();

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'variant_id' => $black->id, 'quantity' => 25])
            ->assertOk();
        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'variant_id' => $white->id, 'quantity' => 35])
            ->assertOk();

        $this->assertSame(25, CartItem::query()->where('variant_id', $black->id)->value('quantity'));
        $this->assertSame(30, CartItem::query()->where('variant_id', $white->id)->value('quantity'));
    }

    private function purchase(Buyer $buyer, Seller $seller, Product $product, int $quantity, OrderStatus $status = OrderStatus::Placed): void
    {
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $sellerOrder = SellerOrder::factory()->status($status)->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
        ]);
        OrderItem::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $quantity,
        ]);
    }
}
