<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_product_page_renders_description(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'description' => "Line one\nLine two <script>alert(1)</script>",
        ]);

        $this->get(route('buyer.products.show', $product->id))
            ->assertOk()
            ->assertSee('Line one', false)
            ->assertSee('Line two', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_shop_index_hides_out_of_stock_products(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'stock_quantity' => 5,
            'name' => 'In Stock Widget',
        ]);

        Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'stock_quantity' => 0,
            'name' => 'Out Of Stock Widget',
        ]);

        $this->get(route('buyer.products.index'))
            ->assertOk()
            ->assertSee('In Stock Widget', false)
            ->assertDontSee('Out Of Stock Widget', false);
    }

    public function test_load_more_endpoint_returns_next_page_products(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        // Create 45 products (initial page loads 20, load-more should load next 20)
        for ($i = 1; $i <= 45; $i++) {
            Product::factory()->create([
                'seller_id' => $seller->id,
                'status' => 'active',
                'stock_quantity' => 5,
                'name' => "Product {$i}",
            ]);
        }

        // The button only works if the shop page loads its script
        $this->get(route('buyer.products.index'))->assertSee('products-load-more', false);

        // Request page 2
        $response = $this->get(route('buyer.products.load-more', ['page' => 2]));

        $response->assertOk()
            ->assertJson([
                'total' => 45,
                'showing' => 40, // 2 pages * 20 products
                'has_more' => true,
                'next_page' => 3,
            ]);

        // Verify HTML contains product cards
        $data = $response->json();
        $this->assertNotEmpty($data['html']);
        $this->assertStringContainsString('buyer-product-card', $data['html']);
    }

    public function test_load_more_endpoint_hides_has_more_on_last_page(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        // Create 13 products (page 1: 12, page 2: 1)
        for ($i = 1; $i <= 13; $i++) {
            Product::factory()->create([
                'seller_id' => $seller->id,
                'status' => 'active',
                'stock_quantity' => 5,
            ]);
        }

        // Request page 2 (last page)
        $response = $this->get(route('buyer.products.load-more', ['page' => 2]));

        $response->assertJson([
            'total' => 13,
            'showing' => 13,
            'has_more' => false,
            'next_page' => 3,
        ]);
    }

    public function test_load_more_endpoint_filters_by_search_term(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        // Create products with different names
        Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'stock_quantity' => 5,
            'name' => 'Widget Pro',
        ]);

        Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'stock_quantity' => 5,
            'name' => 'Gadget Max',
        ]);

        // Request with search term
        $response = $this->get(route('buyer.products.load-more', [
            'page' => 1,
            'q' => 'Widget',
        ]));

        $response->assertOk();
        $data = $response->json();
        $this->assertLessThanOrEqual(1, $data['total']);
    }
}
