<?php

namespace Tests\Feature\Buyer;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SearchPaginationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function listing(Seller $seller, Category $category, string $name, int $price = 150, int $stock = 8): Product
    {
        return Product::factory()->create([
            'seller_id' => $seller->id, 'category_id' => $category->id, 'name' => $name,
            'price' => $price, 'stock_quantity' => $stock,
            'created_at' => '2026-10-01 10:00:00',
        ]);
    }

    private function paginationLinks(string $html): array
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $links = [];
        foreach ((new \DOMXPath($document))->query('//a[@href]') as $node) {
            $url = $node->getAttribute('href');
            $path = parse_url($url, PHP_URL_PATH);
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            if ($path === '/buyer/search' && isset($query['page'])) {
                $links[] = ['url' => $url, 'query' => $query];
            }
        }

        return $links;
    }

    public static function sorts(): array
    {
        return [['relevance'], ['newest'], ['oldest'], ['price_asc'], ['price_desc']];
    }

    #[DataProvider('sorts')]
    public function test_rendered_links_keep_supported_parameters_and_pages_are_deterministic(string $sort): void
    {
        $seller = Seller::factory()->create();
        $category = Category::create(['label' => 'Pagination', 'icon' => 'shirt']);
        $ids = [];
        for ($i = 1; $i <= 25; $i++) {
            $ids[] = $this->listing($seller, $category, 'Shirt & Linen '.$i)->id;
        }
        $expected = in_array($sort, ['relevance', 'newest'], true) ? array_reverse($ids) : $ids;
        $filters = ['q' => 'Shirt & Linen', 'category' => $category->id, 'seller' => $seller->id, 'sort' => $sort, 'min_price' => 0, 'max_price' => 500];

        $first = $this->get(route('buyer.search.index', $filters))->assertOk();
        $this->assertSame(array_slice($expected, 0, 12), $first->viewData('products')->modelKeys());
        $links = $this->paginationLinks($first->getContent());
        $this->assertNotEmpty($links);
        foreach ($links as $link) {
            foreach ($filters as $key => $value) {
                $this->assertSame((string) $value, $link['query'][$key]);
            }
        }
        $pageTwo = collect($links)->firstWhere('query.page', '2');
        $this->assertNotNull($pageTwo);
        $second = $this->get($pageTwo['url'])->assertOk();
        $this->assertSame(array_slice($expected, 12, 12), $second->viewData('products')->modelKeys());
        foreach ($this->paginationLinks($second->getContent()) as $link) {
            foreach ($filters as $key => $value) {
                $this->assertSame((string) $value, $link['query'][$key]);
            }
        }
        $third = $this->get(route('buyer.search.index', $filters + ['page' => 3]))->assertOk();
        $actual = array_merge($first->viewData('products')->modelKeys(), $second->viewData('products')->modelKeys(), $third->viewData('products')->modelKeys());
        $this->assertSame($expected, $actual);
        $this->assertSame(25, count(array_unique($actual)));
        $repeat = $this->get($pageTwo['url'])->assertOk();
        $this->assertSame($second->viewData('products')->modelKeys(), $repeat->viewData('products')->modelKeys());
    }

    public function test_category_seller_price_and_t09_eligibility_remain_applied_on_page_two(): void
    {
        $seller = Seller::factory()->create();
        $category = Category::create(['label' => 'Filtered', 'icon' => 'shirt']);
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->listing($seller, $category, 'Filter Shirt '.$i)->id;
        }
        $expensive = $this->listing($seller, $category, 'Filter Shirt Eligible Variant', 1, 0);
        app(VariantSyncService::class)->sync($expensive,
            [['name' => 'Color', 'values' => ['Black', 'White']]],
            [
                ['values' => ['Black'], 'price' => 1, 'stock_quantity' => 7, 'is_available' => false],
                ['values' => ['White'], 'price' => 150, 'stock_quantity' => 4, 'is_available' => true],
            ], true);
        $ids[] = $expensive->id;
        $this->listing($seller, $category, 'Filter Shirt Too Cheap', 25);
        $this->listing($seller, $category, 'Filter Shirt Too Expensive', 300);
        $this->listing($seller, Category::create(['label' => 'Other', 'icon' => 'shirt']), 'Filter Shirt Other Category');
        $this->listing(Seller::factory()->create(), $category, 'Filter Shirt Other Seller');
        $this->listing(Seller::factory()->create(['status' => 'suspended']), $category, 'Filter Shirt Suspended');
        $disabled = $this->listing($seller, $category, 'Filter Shirt Disabled');
        $disabled->variants()->update(['is_available' => false]);
        $this->listing($seller, $category, 'Filter Shirt Empty', stock: 0);
        $this->listing($seller, $category, 'Filter Shirt Archived')->delete();
        $this->listing($seller, $category, 'Filter Shirt Inactive')->update(['status' => 'inactive']);
        $this->listing($seller, $category, 'Filter Shirt Missing Variants')->variants()->delete();
        $this->listing($seller, $category, 'Unrelated Product');
        $filters = ['q' => 'Filter Shirt', 'category' => $category->id, 'seller' => $seller->id, 'sort' => 'price_desc', 'min_price' => 100, 'max_price' => 200];

        $first = $this->get(route('buyer.search.index', $filters))->assertOk();
        $this->assertSame(13, $first->viewData('products')->total());
        $this->assertSame(array_slice($ids, 0, 12), $first->viewData('products')->modelKeys());
        $link = collect($this->paginationLinks($first->getContent()))->firstWhere('query.page', '2');
        $this->assertNotNull($link);
        $second = $this->get($link['url'])->assertOk()->assertSee('Filter Shirt Eligible Variant')->assertSee('150.00');
        $this->assertSame([$expensive->id], $second->viewData('products')->modelKeys());
        $this->assertSame(13, $second->viewData('products')->total());

        // T11 submits to the catalog with these same supported filters.
        foreach ([1, 2] as $page) {
            $catalog = $this->get(route('buyer.products.index', $filters + ['page' => $page]))->assertOk();
            $search = $this->get(route('buyer.search.index', $filters + ['page' => $page]))->assertOk();
            $this->assertSame($catalog->viewData('products')->modelKeys(), $search->viewData('products')->modelKeys());
        }
    }

    public static function emptySearches(): array
    {
        return [[[]], [['q' => '']], [['q' => '   ']], [['q' => 'x']]];
    }

    #[DataProvider('emptySearches')]
    public function test_empty_or_short_searches_on_later_pages_are_safe(array $query): void
    {
        $seller = Seller::factory()->create();
        $category = Category::create(['label' => 'Empty Search', 'icon' => 'shirt']);
        $this->listing($seller, $category, 'A Shirt');

        $response = $this->get(route('buyer.search.index', $query + ['page' => 2]))->assertOk()->assertSee('No results found');

        $this->assertTrue($response->viewData('products')->isEmpty());
        $this->assertSame([], $this->paginationLinks($response->getContent()));
    }

    public function test_unmatched_and_out_of_range_pages_render_safely(): void
    {
        $seller = Seller::factory()->create();
        $category = Category::create(['label' => 'Safe Pages', 'icon' => 'shirt']);
        $this->listing($seller, $category, 'Only Shirt');

        foreach ([['q' => 'nonexistent', 'page' => 2], ['q' => 'Shirt', 'page' => 99]] as $query) {
            $response = $this->get(route('buyer.search.index', $query))->assertOk()->assertSee('No results found');
            $this->assertTrue($response->viewData('products')->isEmpty());
        }
    }
}
