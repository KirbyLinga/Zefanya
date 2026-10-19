<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_buyer_home_page_loads(): void
    {
        $this->get(route('buyer.home'))
            ->assertOk()
            ->assertViewIs('Buyer.Home.index')
            ->assertViewHas('categories')
            ->assertViewHas('flashProducts')
            ->assertViewHas('newArrivals')
            ->assertViewHas('forYouProducts');
    }

    public function test_search_page_displays_results(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => 'Test Product',
            'status' => 'active',
            'stock_quantity' => 10,
        ]);

        $this->get(route('buyer.search.index', ['q' => 'Test']))
            ->assertOk()
            ->assertViewIs('Buyer.Search.index')
            ->assertViewHas('products')
            ->assertViewHas('query', 'Test')
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$product->id]);
    }

    public function test_search_suggestions_returns_popular_products(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => 'Organic Coffee',
            'status' => 'active',
            'stock_quantity' => 10,
        ]);

        $this->getJson(route('buyer.search.suggestions', ['term' => 'coffee']))
            ->assertOk()
            ->assertJsonStructure([
                'recent',
                'popular',
            ])
            ->assertJsonCount(1, 'popular')
            ->assertJsonPath('popular.0.id', $product->id);
    }

    public function test_search_matches_description_and_category_without_exposing_inactive_or_unapproved_products(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $category = Category::create(['label' => 'Coffee Supplies', 'icon' => 'coffee']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id, 'name' => 'Morning Blend',
            'description' => 'Roasted COFFEE beans', 'status' => 'active',
        ]);
        Product::factory()->create(['seller_id' => $seller->id, 'name' => 'Coffee Draft', 'status' => 'inactive']);
        $pendingSeller = Seller::factory()->create(['status' => 'pending']);
        Product::factory()->create(['seller_id' => $pendingSeller->id, 'name' => 'Coffee Pending', 'status' => 'active']);

        $this->get(route('buyer.search.index', ['q' => 'coffee']))
            ->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$product->id])
            ->assertViewHas('categories', fn ($categories) => $categories->pluck('id')->all() === [$category->id]);
        $this->getJson(route('buyer.search.suggestions', ['term' => 'coffee']))
            ->assertOk()->assertJsonCount(0, 'popular');
    }

    public function test_search_treats_sql_syntax_as_bound_text(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        Product::factory()->create(['seller_id' => $seller->id, 'status' => 'active']);
        $term = "' OR 1=1 --";

        $this->get(route('buyer.search.index', ['q' => $term]))
            ->assertOk()->assertViewHas('products', fn ($products) => $products->isEmpty());
        $this->getJson(route('buyer.search.suggestions', ['term' => $term]))
            ->assertOk()->assertJsonCount(0, 'popular');
    }

    public function test_authenticated_buyer_can_access_mini_cart(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['price' => 10.00, 'status' => 'active', 'stock_quantity' => 10]);

        // Add to cart first
        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.cart.add'), [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertOk();

        // Get mini cart
        $this->actingAs($buyer, 'buyer')
            ->getJson(route('buyer.cart.mini'))
            ->assertOk()
            ->assertJsonStructure([
                'items' => [
                    '*' => ['id', 'name', 'price', 'quantity', 'image_url'],
                ],
                'count',
                'subtotal',
                'total',
            ]);
    }

    public function test_authenticated_buyer_can_toggle_wishlist(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['status' => 'active', 'stock_quantity' => 10]);

        // Add to wishlist
        $response = $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.wishlist.toggle', $product->id))
            ->assertOk()
            ->assertJsonPath('isSaved', true)
            ->assertJsonPath('success', true);

        // Remove from wishlist
        $this->actingAs($buyer, 'buyer')
            ->postJson(route('buyer.wishlist.toggle', $product->id))
            ->assertOk()
            ->assertJsonPath('isSaved', false);
    }

    public function test_all_page_routes_exist(): void
    {
        $pages = [
            'shipping-returns',
            'privacy-policy',
            'terms-of-service',
            'about',
            'contact',
        ];

        foreach ($pages as $page) {
            $this->get(route('buyer.pages.'.$page))
                ->assertOk();
        }
    }

    public function test_guest_cannot_access_wishlist_page(): void
    {
        $this->get(route('buyer.wishlist.index'))
            ->assertRedirect(route('buyer.login'));
    }
}
