<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Models\Shared\OrderItem;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the Buyer product listing (Stage 1 query optimization).
 *
 * Protects correct results, ordering, filtering and pagination after the
 * two-phase refactor (select page IDs first, then load rating/sales/stock
 * aggregates only for the page).
 */
class ProductListingRegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Seller $seller;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->category = Category::create(['label' => 'Regression', 'icon' => 'shirt']);
    }

    private function listing(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'status' => 'active',
            'stock_quantity' => 10,
        ], $attributes));
    }

    private function fulfilledSales(Product $product, int $quantity): void
    {
        $order = SellerOrder::factory()->status(OrderStatus::Delivered)->create(['seller_id' => $this->seller->id]);
        OrderItem::factory()->create([
            'seller_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);
    }

    public function test_index_excludes_ineligible_products(): void
    {
        $inStock = $this->listing(['name' => 'In Stock', 'price' => 100]);

        $this->listing(['name' => 'Draft', 'status' => 'draft', 'price' => 1]);
        $this->listing(['name' => 'Inactive', 'status' => 'inactive', 'price' => 2]);
        $this->listing(['name' => 'Restricted', 'price' => 3, 'restriction_reason' => 'Admin review']);
        $this->listing(['name' => 'Out of Stock', 'price' => 4, 'stock_quantity' => 0]);

        $unapproved = Seller::factory()->create(['status' => 'pending']);
        Product::factory()->create([
            'seller_id' => $unapproved->id,
            'category_id' => $this->category->id,
            'name' => 'Unapproved Seller',
            'price' => 5,
            'stock_quantity' => 10,
            'status' => 'active',
        ]);

        $products = $this->get(route('buyer.products.index'))
            ->assertOk()
            ->viewData('products');

        $this->assertSame([$inStock->id], $products->modelKeys());
    }

    public function test_index_price_asc_and_price_desc_are_correct(): void
    {
        $cheap = $this->listing(['name' => 'Cheap', 'price' => 100, 'created_at' => '2026-01-01']);
        $mid = $this->listing(['name' => 'Mid', 'price' => 200, 'created_at' => '2026-01-02']);
        $dear = $this->listing(['name' => 'Dear', 'price' => 300, 'created_at' => '2026-01-03']);

        $asc = $this->get(route('buyer.products.index', ['sort' => 'price_asc']))
            ->assertOk()
            ->viewData('products')
            ->modelKeys();
        $this->assertSame([$cheap->id, $mid->id, $dear->id], $asc);

        $desc = $this->get(route('buyer.products.index', ['sort' => 'price_desc']))
            ->assertOk()
            ->viewData('products')
            ->modelKeys();
        $this->assertSame([$dear->id, $mid->id, $cheap->id], $desc);
    }

    public function test_index_newest_sort_is_correct(): void
    {
        $old = $this->listing(['name' => 'Old', 'price' => 100, 'created_at' => '2026-01-01']);
        $mid = $this->listing(['name' => 'Mid', 'price' => 100, 'created_at' => '2026-01-02']);
        $new = $this->listing(['name' => 'New', 'price' => 100, 'created_at' => '2026-01-03']);

        $ids = $this->get(route('buyer.products.index', ['sort' => 'newest']))
            ->assertOk()
            ->viewData('products')
            ->modelKeys();
        $this->assertSame([$new->id, $mid->id, $old->id], $ids);
    }

    public function test_index_pagination_does_not_repeat_or_skip_products(): void
    {
        $ids = [];
        foreach (range(1, 25) as $i) {
            $ids[] = $this->listing(['name' => "Prod {$i}", 'price' => 100, 'created_at' => '2026-10-01 10:00:00'])->id;
        }

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $seen = array_merge($seen, $this->get(route('buyer.products.index', ['page' => $page]))
                ->assertOk()
                ->viewData('products')
                ->modelKeys());
        }

        $this->assertCount(25, $seen);
        $this->assertEqualsCanonicalizing($ids, $seen);
    }

    public function test_index_pagination_preserves_price_filters_and_sort(): void
    {
        $ids = [];
        foreach (range(1, 25) as $i) {
            $ids[] = $this->listing(['name' => "InRange {$i}", 'price' => 100 + $i])->id;
        }
        $this->listing(['name' => 'Out of Range High', 'price' => 900]);
        $this->listing(['name' => 'Out of Range Low', 'price' => 1]);

        $first = $this->get(route('buyer.products.index', [
            'min_price' => 100, 'max_price' => 200, 'sort' => 'price_asc', 'page' => 1,
        ]))->assertOk();
        // ProductController::PER_PAGE: 20 keeps the 2, 4 and 5 column grid rows full.
        $this->assertSame(25, $first->viewData('products')->total());
        $this->assertSame(20, $first->viewData('products')->count());
        $next = $first->viewData('products')->nextPageUrl();
        $this->assertStringContainsString('min_price=100', $next);
        $this->assertStringContainsString('max_price=200', $next);
        $this->assertStringContainsString('sort=price_asc', $next);

        $second = $this->get($next)->assertOk();
        $this->assertSame(25, $second->viewData('products')->total());
        $this->assertSame(5, $second->viewData('products')->count());
        $merged = array_merge(
            $first->viewData('products')->modelKeys(),
            $second->viewData('products')->modelKeys(),
        );
        $this->assertSame($ids, $merged);
    }

    public function test_index_sold_units_and_rating_aggregates_are_correct(): void
    {
        $product = $this->listing(['name' => 'Rated', 'price' => 100]);
        $this->fulfilledSales($product, 5);

        $response = $this->get(route('buyer.products.index'))->assertOk();
        $row = $response->viewData('products')->firstWhere('id', $product->id);
        $this->assertSame(5, (int) $row->sold_units);
        $this->assertSame(0, (int) $row->reviews_count);
    }
}
