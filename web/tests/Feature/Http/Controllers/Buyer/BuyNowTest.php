<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BuyNowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_rejected(): void
    {
        $this->postJson('/buyer/checkout/buy-now', ['product_id' => 1, 'quantity' => 1])
            ->assertUnauthorized();
    }

    public function test_it_scopes_checkout_to_a_single_line(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 5]);

        $otherItem = CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'quantity' => 3,
        ]);

        $response = $this->actingAs($buyer, 'buyer')
            ->postJson('/buyer/checkout/buy-now', ['product_id' => $product->id, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('success', true);

        $item = CartItem::query()
            ->where('buyer_id', $buyer->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $redirect = urldecode($response->json('redirect'));

        $this->assertSame(2, $item->quantity);
        $this->assertStringContainsString('items[0]='.$item->id, $redirect);
        $this->assertStringNotContainsString('items[1]', $redirect);
    }

    public function test_it_sets_rather_than_adds_quantity(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 10]);

        $this->actingAs($buyer, 'buyer')
            ->postJson('/buyer/checkout/buy-now', ['product_id' => $product->id, 'quantity' => 3])
            ->assertOk();

        $this->actingAs($buyer, 'buyer')
            ->postJson('/buyer/checkout/buy-now', ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk();

        $lines = CartItem::query()
            ->where('buyer_id', $buyer->id)
            ->where('product_id', $product->id)
            ->get();

        $this->assertCount(1, $lines);
        $this->assertSame(1, $lines->first()->quantity);
    }

    public function test_cart_count_is_the_quantity_sum(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 20]);
        $anotherProduct = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 20]);

        // Pre-existing line for a DIFFERENT product
        CartItem::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $anotherProduct->id,
            'variant_id' => null,
            'quantity' => 2,
        ]);

        $this->actingAs($buyer, 'buyer')
            ->postJson('/buyer/checkout/buy-now', ['product_id' => $product->id, 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('cart_count', 5);
    }

    public function test_it_rejects_inactive_products(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'draft']);

        $this->actingAs($buyer, 'buyer')
            ->postJson('/buyer/checkout/buy-now', ['product_id' => $product->id, 'quantity' => 1])
            ->assertUnprocessable();
    }

    public function test_it_caps_quantity_at_stock(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'stock_quantity' => 3]);

        $this->actingAs($buyer, 'buyer')
            ->postJson('/buyer/checkout/buy-now', ['product_id' => $product->id, 'quantity' => 99])
            ->assertOk()
            ->assertJsonPath('capped', true);

        $item = CartItem::query()
            ->where('buyer_id', $buyer->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertSame(3, $item->quantity);
    }
}
