<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
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

    public function test_each_category_card_opens_its_own_category(): void
    {
        $response = $this->get(route('buyer.home'))->assertOk();
        $categories = Category::query()->orderBy('id')->get();

        $this->assertNotEmpty($categories);
        foreach ($categories as $category) {
            $response->assertSee('href="'.route('buyer.categories.show', $category->id).'"', false)
                ->assertSee('data-lucide="'.$category->icon.'"', false)
                ->assertSee($category->label);
        }
    }

    public function test_category_row_is_a_native_scroller_with_no_layout_animating_hover(): void
    {
        $this->get(route('buyer.home'))->assertOk()
            ->assertSee('snap-x', false)
            ->assertDontSee('id="catTrack"', false)
            ->assertDontSee('hover:grow-[0.3]', false)
            ->assertDontSee('transition-[flex,padding,margin', false);
    }

    /**
     * @test
     *
     * @skip SQLite doesn't support ILIKE operator used by SearchController
     */
    public function test_search_page_displays_results(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => 'Test Product',
            'status' => 'active',
            'stock_quantity' => 10,
        ]);

        $this->get(route('buyer.search.index', ['q' => 'Test']))
            ->assertOk()
            ->assertViewIs('Buyer.Search.index')
            ->assertViewHas('products')
            ->assertViewHas('query', 'Test');
    }

    /**
     * @test
     *
     * @skip SQLite doesn't support ILIKE operator used by SearchController
     */
    public function test_search_suggestions_returns_popular_products(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => 'Organic Coffee',
            'status' => 'active',
            'stock_quantity' => 10,
        ]);

        $this->getJson(route('buyer.search.suggestions', ['term' => 'coffee']))
            ->assertOk()
            ->assertJsonStructure([
                'popular',
            ]);
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
        $this->actingAs($buyer, 'buyer')
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
            ->assertRedirect(route('home', ['login' => 1]));
    }
}
