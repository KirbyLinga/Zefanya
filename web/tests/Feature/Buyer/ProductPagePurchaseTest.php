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
use App\Services\Buyer\BuyerPurchaseLimit;
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

    public function test_buy_pdp_004_quantity_is_kept_between_one_and_twenty(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 40, 'status' => 'active', 'price' => 10]);
        ProductVariant::query()->where('product_id', $product->id)->update(['stock_quantity' => 40, 'is_available' => true]);

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.products.show', $product))
            ->assertOk()
            ->assertSee('max="20"', false)
            ->assertSee('data-purchase-max="20"', false);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 25])
            ->assertOk();

        $this->assertSame(20, CartItem::query()->where('buyer_id', $buyer->id)->value('quantity'));

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 0])
            ->assertUnprocessable();
    }

    public function test_buy_pdp_005_the_twenty_unit_limit_survives_a_new_cart(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'stock_quantity' => 40,
            'status' => 'active',
        ]);
        ProductVariant::query()->where('product_id', $product->id)->update(['stock_quantity' => 40, 'is_available' => true]);
        $this->purchase($buyer, $seller, $product, 20);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quantity.0', BuyerPurchaseLimit::MESSAGE);

        $this->assertSame(0, CartItem::query()->where('buyer_id', $buyer->id)->count());
    }

    public function test_buy_pdp_005_a_new_cart_can_take_only_what_is_left_and_a_cancelled_order_does_not_count(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'stock_quantity' => 40,
            'status' => 'active',
        ]);
        ProductVariant::query()->where('product_id', $product->id)->update(['stock_quantity' => 40, 'is_available' => true]);
        $this->purchase($buyer, $seller, $product, 15, OrderStatus::Cancelled);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 20])
            ->assertOk();
        $this->assertSame(20, CartItem::query()->where('buyer_id', $buyer->id)->value('quantity'));

        CartItem::query()->where('buyer_id', $buyer->id)->delete();
        $this->purchase($buyer, $seller, $product, 15);

        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 10])
            ->assertOk();

        $this->assertSame(5, CartItem::query()->where('buyer_id', $buyer->id)->value('quantity'));
    }

    public function test_buy_pdp_005_variants_of_one_product_share_the_buyer_limit(): void
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
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'variant_id' => $black->id, 'quantity' => 12])
            ->assertOk();
        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'variant_id' => $white->id, 'quantity' => 12])
            ->assertOk();

        $this->assertSame(12, CartItem::query()->where('variant_id', $black->id)->value('quantity'));
        $this->assertSame(8, CartItem::query()->where('variant_id', $white->id)->value('quantity'));
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
