<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\StoreFollow;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreSale;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_can_view_the_buyer_home_page_layout(): void
    {
        $response = $this->get(route('buyer.home'));

        $response->assertOk();
        $response->assertSee('Discover Zefanya');
        $response->assertDontSee('Flash Sale');
        $response->assertSee('Todays For You!');
        $response->assertSee('Best Selling Store');
        $response->assertSee('Product coming soon');
    }

    public function test_home_lists_active_in_stock_products_and_hides_unavailable_ones(): void
    {
        $seller = Seller::factory()->create(['business_name' => 'Hearth Nest']);

        $visible = Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => 'Linen Blend Throw Pillow',
            'price' => 76.00,
            'status' => 'active',
            'stock_quantity' => 12,
        ]);

        Product::factory()->draft()->create([
            'seller_id' => $seller->id,
            'name' => 'Hidden Draft Coat',
        ]);

        Product::factory()->outOfStock()->create([
            'seller_id' => $seller->id,
            'name' => 'Sold Out Camera Bag',
            'status' => 'active',
        ]);

        $response = $this->get(route('buyer.home'));

        $response->assertOk();
        $response->assertSee('Linen Blend Throw Pillow');
        $response->assertSee('₱76.00');
        $response->assertDontSee('Hidden Draft Coat');
        $response->assertDontSee('Sold Out Camera Bag');
        $response->assertSee('Hearth Nest');
        $this->assertModelExists($visible);
    }

    public function test_home_escapes_product_and_store_names_in_html(): void
    {
        $seller = Seller::factory()->create([
            'business_name' => "Nest <script>alert('store')</script>",
        ]);

        Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => "Pillow <script>alert('xss')</script>",
        ]);

        $response = $this->get(route('buyer.home'));

        $response->assertOk();
        $this->assertStringContainsString('&lt;script&gt;', $response->getContent());
        $this->assertStringNotContainsString("<script>alert('xss')</script>", $response->getContent());
        $this->assertStringNotContainsString("<script>alert('store')</script>", $response->getContent());
    }

    public function test_home_omits_unapproved_sellers_from_best_selling_stores(): void
    {
        $pending = Seller::factory()->pending()->create([
            'business_name' => 'Pending Boutique',
        ]);

        Product::factory()->create([
            'seller_id' => $pending->id,
            'name' => 'Pending Only Product',
        ]);

        $response = $this->get(route('buyer.home'));

        $response->assertOk();
        $response->assertDontSee('Pending Boutique');
        $response->assertDontSee('Pending Only Product');
    }

    public function test_for_you_products_are_distributed_across_multiple_sellers(): void
    {
        // Create 3 sellers with multiple products each
        $seller1 = Seller::factory()->create(['status' => 'approved']);
        $seller2 = Seller::factory()->create(['status' => 'approved']);
        $seller3 = Seller::factory()->create(['status' => 'approved']);

        // Each seller has 5 products
        for ($i = 0; $i < 5; $i++) {
            Product::factory()->create([
                'seller_id' => $seller1->id,
                'status' => 'active',
                'stock_quantity' => 10,
                'name' => "Seller1 Product $i",
            ]);
            Product::factory()->create([
                'seller_id' => $seller2->id,
                'status' => 'active',
                'stock_quantity' => 10,
                'name' => "Seller2 Product $i",
            ]);
            Product::factory()->create([
                'seller_id' => $seller3->id,
                'status' => 'active',
                'stock_quantity' => 10,
                'name' => "Seller3 Product $i",
            ]);
        }

        $response = $this->get(route('buyer.home'));

        $response->assertOk();

        // Extract seller IDs from visible products
        $sellerIds = [];
        for ($i = 0; $i < 5; $i++) {
            if ($response->getContent() && strpos($response->getContent(), "Seller1 Product $i") !== false) {
                $sellerIds[] = $seller1->id;
            }
            if ($response->getContent() && strpos($response->getContent(), "Seller2 Product $i") !== false) {
                $sellerIds[] = $seller2->id;
            }
            if ($response->getContent() && strpos($response->getContent(), "Seller3 Product $i") !== false) {
                $sellerIds[] = $seller3->id;
            }
        }

        // Verify diversity: should have products from all 3 sellers
        $uniqueSellers = count(array_unique($sellerIds));
        $this->assertGreaterThan(1, $uniqueSellers, 'For You section should have products from multiple sellers');
        $this->assertLessThanOrEqual(3, $uniqueSellers, 'Should have at most 3 sellers represented');
    }

    public function test_for_you_section_shows_only_active_in_stock_products(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        // Create mix of products
        $visible = Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'stock_quantity' => 10,
            'name' => 'Visible Product',
        ]);

        Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'draft',
            'stock_quantity' => 10,
            'name' => 'Draft Product',
        ]);

        Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'stock_quantity' => 0,
            'name' => 'Out of Stock Product',
        ]);

        $response = $this->get(route('buyer.home'));

        $response->assertOk();
        $response->assertSee('Visible Product');
        $response->assertDontSee('Draft Product');
        $response->assertDontSee('Out of Stock Product');
    }

    public function test_a_seller_with_older_products_is_not_crowded_out_by_a_prolific_seller(): void
    {
        $quiet = Seller::factory()->create(['business_name' => 'Quiet Store']);
        $quietProduct = Product::factory()->create(['seller_id' => $quiet->id]);

        $prolific = Seller::factory()->create();
        Product::factory()->count(25)->create(['seller_id' => $prolific->id]);

        $response = $this->get(route('buyer.home'))->assertOk();

        $this->assertContains($quietProduct->id, $response->viewData('newArrivals')->modelKeys());
    }

    public function test_recommendations_skip_stores_on_vacation(): void
    {
        $away = Seller::factory()->create(['is_on_vacation' => true]);
        $awayProduct = Product::factory()->create(['seller_id' => $away->id]);
        $open = Product::factory()->create(['seller_id' => Seller::factory()->create()->id]);

        $response = $this->get(route('buyer.home'))->assertOk();

        $this->assertSame([$open->id], $response->viewData('newArrivals')->modelKeys());
        $this->assertNotContains($awayProduct->id, $response->viewData('forYouProducts')->modelKeys());
    }

    public function test_on_sale_lists_real_discounts_not_already_in_flash_sale(): void
    {
        $seller = Seller::factory()->create();
        $products = Product::factory()->count(5)->create(['seller_id' => $seller->id]);
        foreach ($products as $product) {
            StoreSale::factory()->percent(20)->create(['seller_id' => $seller->id, 'product_id' => $product->id]);
        }
        $ended = Product::factory()->create(['seller_id' => $seller->id]);
        StoreSale::factory()->ended()->create(['seller_id' => $seller->id, 'product_id' => $ended->id]);

        $response = $this->get(route('buyer.home'))->assertOk();
        $flash = $response->viewData('flashProducts')->modelKeys();
        $onSale = $response->viewData('onSaleProducts')->modelKeys();

        $this->assertCount(4, $flash);
        $this->assertCount(1, $onSale);
        $this->assertEmpty(array_intersect($flash, $onSale));
        $this->assertNotContains($ended->id, array_merge($flash, $onSale));
        $response->assertSee('On Sale');
    }

    public function test_trending_ranks_recent_fulfilled_units_only(): void
    {
        $seller = Seller::factory()->create();
        $top = Product::factory()->create(['seller_id' => $seller->id]);
        $second = Product::factory()->create(['seller_id' => $seller->id]);
        $undelivered = Product::factory()->create(['seller_id' => $seller->id]);
        $stale = Product::factory()->create(['seller_id' => $seller->id]);

        $this->soldUnits($top, 5, OrderStatus::Delivered);
        $this->soldUnits($second, 2, OrderStatus::Completed);
        $this->soldUnits($undelivered, 9, OrderStatus::Cancelled);
        $old = $this->soldUnits($stale, 9, OrderStatus::Completed);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();

        $response = $this->get(route('buyer.home'))->assertOk();

        $this->assertSame([$top->id, $second->id], $response->viewData('trendingProducts')->modelKeys());
    }

    public function test_home_hides_new_sections_when_there_is_nothing_to_show(): void
    {
        $response = $this->get(route('buyer.home'))->assertOk();

        $response->assertDontSee('New Arrivals');
        $response->assertDontSee('Trending Products');
        $response->assertDontSee('Explore Stores');
        $response->assertDontSee('id="on-sale"', false);
    }

    public function test_explore_stores_lists_sellers_beyond_the_sidebar_with_live_products(): void
    {
        $sellers = Seller::factory()->count(4)->create();
        $sellers->each(fn (Seller $seller) => Product::factory()->create(['seller_id' => $seller->id]));
        $empty = Seller::factory()->create();

        $response = $this->get(route('buyer.home'))->assertOk();
        $explore = $response->viewData('exploreStores')->modelKeys();

        $response->assertSee('Explore Stores');
        $this->assertEqualsCanonicalizing($sellers->modelKeys(), $explore);
        $this->assertNotContains($empty->id, $explore);
    }

    public function test_trust_badges_only_claim_what_the_platform_does(): void
    {
        $response = $this->get(route('buyer.home'))->assertOk();

        $response->assertSee('Cash on Delivery');
        $response->assertSee('Order Tracking');
        $response->assertDontSee('Secure Checkout');
        $response->assertDontSee('Real-time Tracking');
        $response->assertDontSee('encrypted');
        $response->assertDontSee('Buyer protection');
    }

    public function test_flash_sale_makes_no_unsupported_promises(): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        StoreSale::factory()->create(['seller_id' => $seller->id, 'product_id' => $product->id]);

        $response = $this->get(route('buyer.home'))->assertOk();

        $response->assertSee('id="flash-sale"', false);
        $response->assertDontSee('Cashback');
        $response->assertDontSee('guaranteed authentic');
        $response->assertDontSee('Dismiss flash sale');
        $response->assertDontSee('js-home-tab');
    }

    public function test_home_wrappers_are_closed_before_the_footer(): void
    {
        $html = $this->get(route('buyer.home'))->assertOk()->getContent();
        $main = substr($html, strpos($html, '<main'), strpos($html, '</main>') - strpos($html, '<main'));

        $this->assertSame(
            preg_match_all('/<div\b/', $main),
            preg_match_all('/<\/div>/', $main),
        );
    }

    public function test_hero_never_features_a_store_on_vacation(): void
    {
        $away = Seller::factory()->create(['is_on_vacation' => true, 'business_name' => 'Away Store']);
        $product = Product::factory()->create(['seller_id' => $away->id, 'name' => 'Away Lamp']);
        $product->images()->create(['path' => 'products/away.jpg', 'is_primary' => true, 'sort_order' => 1]);
        $this->soldUnits($product, 3, OrderStatus::Delivered);

        $response = $this->get(route('buyer.home'))->assertOk();

        $response->assertDontSee('Away Lamp');
        $response->assertDontSee('Away Store');
    }

    public function test_best_selling_stores_rank_by_delivered_units(): void
    {
        $listings = Seller::factory()->create();
        Product::factory()->count(5)->create(['seller_id' => $listings->id]);
        $selling = Seller::factory()->create();
        $this->soldUnits(Product::factory()->create(['seller_id' => $selling->id]), 4, OrderStatus::Delivered);

        $stores = $this->get(route('buyer.home'))->assertOk()->viewData('stores');

        $this->assertSame([$selling->id], $stores->modelKeys());
    }

    public function test_buyer_can_follow_a_store_from_its_card_and_guest_is_asked_to_log_in(): void
    {
        $store = Seller::factory()->create();
        Product::factory()->create(['seller_id' => $store->id]);
        $url = route('buyer.stores.follow', $store);

        $this->get(route('buyer.home'))->assertOk()
            ->assertDontSee('action="'.$url.'"', false)
            ->assertSee('Log in to follow this store');

        $buyer = Buyer::factory()->create();
        StoreFollow::query()->forceCreate(['buyer_id' => $buyer->id, 'seller_id' => $store->id]);

        $this->actingAs($buyer, 'buyer')->get(route('buyer.home'))->assertOk()
            ->assertSee('action="'.$url.'"', false)
            ->assertSee('Unfollow');
    }

    public function test_for_you_rotates_so_every_seller_gets_a_turn(): void
    {
        $sellers = Seller::factory()->count(14)->create();
        $sellers->each(fn (Seller $seller) => Product::factory()->create(['seller_id' => $seller->id]));

        $shown = collect();
        foreach (range(1, 14) as $day) {
            $shown = $shown->merge($this->get(route('buyer.home'))->assertOk()->viewData('forYouProducts')->pluck('seller_id'));
            $this->travel(1)->days();
        }

        // New Arrivals keeps the four newest sellers visible every day.
        $this->assertEqualsCanonicalizing(
            $sellers->take(10)->modelKeys(),
            $shown->unique()->values()->all(),
        );
    }

    private function soldUnits(Product $product, int $quantity, OrderStatus $status): SellerOrder
    {
        $parcel = SellerOrder::factory()->status($status)->create(['seller_id' => $product->seller_id]);
        OrderItem::factory()->create([
            'seller_order_id' => $parcel->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);

        return $parcel;
    }
}
