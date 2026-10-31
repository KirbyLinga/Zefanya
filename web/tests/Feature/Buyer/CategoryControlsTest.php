<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryControlsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function listing(Seller $seller, Category $category, string $name, int $price, string $date = '2026-10-01'): Product
    {
        return Product::factory()->create([
            'seller_id' => $seller->id, 'category_id' => $category->id, 'name' => $name,
            'price' => $price, 'stock_quantity' => 8, 'created_at' => $date,
        ]);
    }

    private function sales(Product $product, OrderStatus $status, int $quantity): void
    {
        $order = SellerOrder::factory()->status($status)->create(['seller_id' => $product->seller_id]);
        OrderItem::factory()->create(['seller_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity]);
    }

    public static function sorts(): array
    {
        return [
            ['relevance', [2, 1, 0], 'Relevance'],
            ['popular', [0, 2, 1], 'Most Popular'],
            ['newest', [1, 0, 2], 'Newest'],
            ['price_asc', [1, 2, 0], 'Price: Low to High'],
            ['price_desc', [0, 2, 1], 'Price: High to Low'],
            ['price-low', [1, 2, 0], 'Price: Low to High'],
            ['price-high', [0, 2, 1], 'Price: High to Low'],
            ['oldest', [2, 0, 1], 'Oldest'],
        ];
    }

    #[DataProvider('sorts')]
    public function test_advertised_sorts_render_the_correct_order_and_selection(string $sort, array $indices, string $label): void
    {
        $seller = Seller::factory()->create();
        $category = Category::create(['label' => 'Controls', 'icon' => 'shirt']);
        $products = [
            $this->listing($seller, $category, 'Control Alpha', 300, '2026-10-02'),
            $this->listing($seller, $category, 'Control Beta', 100, '2026-10-03'),
            $this->listing($seller, $category, 'Control Gamma', 200),
        ];
        $other = Category::create(['label' => 'Other', 'icon' => 'shirt']);
        $this->listing($seller, $other, 'Outside Category', 1);
        $this->sales($products[0], OrderStatus::Delivered, 2);
        $this->sales($products[0], OrderStatus::Completed, 3);
        $this->sales($products[2], OrderStatus::Completed, 1);
        $this->sales($products[1], OrderStatus::Returned, 100);
        $this->sales($products[1], OrderStatus::Placed, 100);

        $response = $this->get(route('buyer.categories.show', ['id' => $category->id, 'sort' => $sort]))->assertOk();
        $expected = array_map(fn ($index) => $products[$index]->id, $indices);
        $this->assertSame($expected, $response->viewData('products')->modelKeys());
        $response->assertSeeInOrder(array_map(fn ($index) => $products[$index]->name, $indices));
        $response->assertSee('Sort by '.$label)->assertDontSee('Outside Category');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $selected = $xpath->query('//input[@name="sort" and @checked]');
        $this->assertCount(1, $selected);
        $this->assertSame($response->viewData('sort'), $selected->item(0)->getAttribute('value'));
        $this->assertCount(1, $xpath->query('//button[@data-products-view="grid" and @type="button" and @aria-pressed="true"]'));
        $this->assertCount(1, $xpath->query('//button[@data-products-view="list" and @type="button" and @aria-pressed="false"]'));
        $this->assertCount(1, $xpath->query('//div[@data-products-grid and @data-layout="grid"]'));
        $this->assertCount(1, $xpath->query('//button[@data-category-sort-toggle and @type="button" and @aria-expanded="false" and @aria-controls="category-sort-menu"]'));
        $this->assertCount(1, $xpath->query('//div[@id="category-sort-menu" and @data-category-sort-menu]'));
        $this->assertCount(count($response->viewData('sortOptions')), $xpath->query('//input[@type="radio" and @name="sort"]'));
    }

    public function test_price_sort_uses_only_purchasable_variants_and_preserves_eligibility(): void
    {
        $seller = Seller::factory()->create();
        $category = Category::create(['label' => 'Prices', 'icon' => 'shirt']);
        $variant = $this->listing($seller, $category, 'Expensive Available', 500);
        $variant->variants()->update(['stock_quantity' => 0]);
        app(VariantSyncService::class)->sync($variant,
            [['name' => 'Color', 'values' => ['Disabled', 'Empty', 'Available']]], [
                ['values' => ['Disabled'], 'price' => 1, 'stock_quantity' => 10, 'is_available' => false],
                ['values' => ['Empty'], 'price' => 2, 'stock_quantity' => 0, 'is_available' => true],
                ['values' => ['Available'], 'price' => 300, 'stock_quantity' => 8, 'is_available' => true],
            ], true);
        $cheap = $this->listing($seller, $category, 'Normal Available', 100);
        $disabled = $this->listing($seller, $category, 'All Disabled', 20);
        $disabled->variants()->update(['is_available' => false]);
        $empty = $this->listing($seller, $category, 'All Empty', 20);
        $empty->variants()->update(['stock_quantity' => 0]);
        $suspended = Seller::factory()->create(['status' => 'suspended']);
        $this->listing($suspended, $category, 'Suspended Seller', 5);
        foreach (['price_asc' => [$cheap->id, $variant->id], 'price_desc' => [$variant->id, $cheap->id]] as $sort => $expected) {
            $response = $this->get(route('buyer.categories.show', ['id' => $category->id, 'sort' => $sort]))->assertOk();
            $this->assertSame($expected, $response->viewData('products')->modelKeys());
            $response->assertSee('300.00')->assertSee('100.00')->assertDontSee('All Disabled')->assertDontSee('All Empty')->assertDontSee('Suspended Seller');
        }
        $filtered = $this->get(route('buyer.categories.show', ['id' => $category->id, 'sort' => 'price_asc', 'min_price' => 200, 'max_price' => 350]))->assertOk();
        $this->assertSame([$variant->id], $filtered->viewData('products')->modelKeys());
    }

    #[DataProvider('paginationSorts')]
    public function test_pagination_retains_category_filters_sort_and_deterministic_ties(string $sort): void
    {
        $seller = Seller::factory()->create();
        $category = Category::create(['label' => 'Pages', 'icon' => 'shirt']);
        $ids = [];
        for ($i = 1; $i <= 23; $i++) {
            $ids[] = $this->listing($seller, $category, 'Page Product '.$i, 150)->id;
        }
        $this->listing($seller, $category, 'Too Cheap', 10);
        $this->listing($seller, $category, 'Too Expensive', 500);
        $expected = in_array($sort, ['relevance', 'newest']) ? array_reverse($ids) : $ids;
        $filters = ['sort' => $sort, 'min_price' => 100, 'max_price' => 200];
        $first = $this->get(route('buyer.categories.show', ['id' => $category->id] + $filters))->assertOk();
        $this->assertSame(array_slice($expected, 0, 20), $first->viewData('products')->modelKeys());
        $document = new \DOMDocument;
        @$document->loadHTML($first->getContent());
        $xpath = new \DOMXPath($document);
        $pageTwo = null;
        foreach ($xpath->query('//a[@href]') as $node) {
            $url = $node->getAttribute('href');
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            if (parse_url($url, PHP_URL_PATH) === '/buyer/categories/'.$category->id && isset($query['page'])) {
                foreach ($filters as $key => $value) {
                    $this->assertSame((string) $value, $query[$key]);
                }
                if ($query['page'] === '2') {
                    $pageTwo = $url;
                }
            }
        }
        $this->assertNotNull($pageTwo);
        $second = $this->get($pageTwo)->assertOk();
        $this->assertSame(array_slice($expected, 20), $second->viewData('products')->modelKeys());
        $this->assertSame($expected, array_merge($first->viewData('products')->modelKeys(), $second->viewData('products')->modelKeys()));
        $this->assertCount(1, $xpath->query('//form[@data-category-sort-form and @method="GET"]'));
        $this->assertCount(1, $xpath->query('//input[@type="hidden" and @name="min_price" and @value="100"]'));
        $this->assertCount(1, $xpath->query('//input[@type="hidden" and @name="max_price" and @value="200"]'));
        $this->assertCount(0, $xpath->query('//input[@name="page"]'));
    }

    public static function paginationSorts(): array
    {
        return [['relevance'], ['popular'], ['newest'], ['price_asc'], ['price_desc']];
    }

    public function test_empty_category_and_unknown_sort_have_safe_controls(): void
    {
        $category = Category::create(['label' => 'Empty Category', 'icon' => 'shirt']);
        $response = $this->get(route('buyer.categories.show', ['id' => $category->id, 'sort' => 'unknown']))->assertOk();
        $response->assertSee('No products in this category')->assertSee('Sort by Relevance');
        $this->assertSame('relevance', $response->viewData('sort'));
        $this->assertCount(0, $response->viewData('products'));
    }

    public function test_category_cards_retain_working_cart_and_wishlist_actions(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        $category = Category::create(['label' => 'Actions', 'icon' => 'shirt']);
        $product = $this->listing($seller, $category, 'Optionless Product', 150);
        $this->actingAs($buyer, 'buyer')->get(route('buyer.categories.show', $category->id))->assertOk()
            ->assertSee('data-wishlist-toggle', false)->assertSee('add-to-cart-btn', false)
            ->assertSee('data-product-id="'.$product->id.'"', false);
        $this->postJson(route('buyer.wishlist.toggle', $product->id))->assertOk();
        $this->assertDatabaseHas('wishlists', ['buyer_id' => $buyer->id, 'product_id' => $product->id]);
        $this->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 1])->assertOk();
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 1]);
    }
}
