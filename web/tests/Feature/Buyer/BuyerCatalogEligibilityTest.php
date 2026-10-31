<?php

namespace Tests\Feature\Buyer;

use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuyerCatalogEligibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(string $name, array $rows, ?Category $category = null): Product
    {
        $category ??= Category::create(['label' => 'Audit Clothing', 'icon' => 'shirt']);
        $product = Product::factory()->create([
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
            'category_id' => $category->id, 'name' => $name, 'price' => 1, 'stock_quantity' => 0,
        ]);
        $values = [];
        $variants = [];
        foreach ($rows as $i => [$price, $stock, $available]) {
            $value = 'Color '.$i;
            $values[] = $value;
            $variants[] = ['values' => [$value], 'price' => $price, 'stock_quantity' => $stock, 'is_available' => $available];
        }
        app(VariantSyncService::class)->sync($product, [['name' => 'Color', 'values' => $values]], $variants, true);

        return $product->fresh();
    }

    public static function stockCases(): array
    {
        return [
            'disabled cheaper' => [[[100, 9, false], [300, 3, true]], true, '300.00', 3],
            'sold-out cheaper' => [[[100, 0, true], [300, 3, true]], true, '300.00', 3],
            'available range' => [[[100, 9, false], [300, 3, true], [500, 4, true]], true, '300.00–₱500.00', 7],
            'all disabled' => [[[100, 9, false], [300, 3, false]], false, 'Unavailable', 0],
            'all sold out' => [[[100, 0, true], [300, 0, true]], false, 'Unavailable', 0],
        ];
    }

    #[DataProvider('stockCases')]
    public function test_catalog_search_categories_and_details_use_only_purchasable_variants(array $rows, bool $listed, string $price, int $stock): void
    {
        $product = $this->product('Eligibility Shirt', $rows);
        $physical = $product->getAttributes();
        $ledger = InventoryTransaction::query()->get()->toArray();
        $listed ? [$product->id] : [];

        foreach ([route('buyer.products.index'), route('buyer.search.index', ['q' => 'Eligibility']), route('buyer.categories.show', $product->category_id)] as $url) {
            $soldOutSearch = str_contains($url, '/search?') && ! $listed && collect($rows)->contains(fn ($row) => $row[2] && $row[1] === 0);
            $visible = $listed || $soldOutSearch;
            $response = $this->get($url)->assertOk()->assertViewHas('products', fn ($items) => $items->modelKeys() === ($visible ? [$product->id] : []));
            if ($visible) {
                $response->assertSee('Eligibility Shirt')->assertSee($price);
                $response->assertSee($soldOutSearch ? 'Sold Out' : ($stock.' in stock'));
            } else {
                $response->assertDontSee('Eligibility Shirt');
            }
        }
        $suggestions = $this->getJson(route('buyer.search.suggestions', ['term' => 'Eligibility']))
            ->assertOk()->assertJsonCount($listed ? 1 : 0, 'popular');
        if ($listed) {
            $suggestions->assertJsonPath('popular.0.price', "\u{20B1}".$price);
        }
        $details = $this->get(route('buyer.products.show', $product))->assertOk();
        $details->assertSee($price);
        if (! $listed) {
            $details->assertSee('Out of Stock');
        }
        $aggregate = Product::query()->withBuyerCardData()->with('seller')->findOrFail($product->id);
        $card = (string) $this->view('Components.buyer.product-card', ['product' => $aggregate, 'wishlistIds' => collect()]);
        $this->assertStringContainsString($listed ? ($stock.' in stock') : 'Out of stock', $card);
        $this->assertStringContainsString($price, $card);
        if (! $listed) {
            $this->assertStringContainsString('disabled', $card);
        }
        $this->assertSame($stock, $aggregate->buyerStockQuantity());
        $this->assertSame($stock, $product->load(['variants', 'seller'])->buyerStockQuantity());
        $this->assertSame($aggregate->formattedPriceRange(), $product->formattedPriceRange());
        $this->assertSame($physical, $product->fresh()->getAttributes());
        $this->assertSame($ledger, InventoryTransaction::query()->get()->toArray());
    }

    public static function catalogRoutes(): array
    {
        return [['shop'], ['search'], ['category']];
    }

    private function url(string $route, Category $category, array $filters): string
    {
        return match ($route) {
            'shop' => route('buyer.products.index', $filters),
            'search' => route('buyer.search.index', ['q' => 'Filter Shirt'] + $filters),
            'category' => route('buyer.categories.show', ['id' => $category->id] + $filters),
        };
    }

    #[DataProvider('catalogRoutes')]
    public function test_filters_and_sorting_share_the_advertised_starting_price(string $route): void
    {
        $category = Category::create(['label' => 'Filters', 'icon' => 'shirt']);
        $high = $this->product('Filter Shirt High', [[1, 12, false], [500, 2, true], [700, 2, true]], $category);
        $middle = $this->product('Filter Shirt Middle', [[2, 0, true], [300, 2, true]], $category);
        $low = $this->product('Filter Shirt Low', [[100, 2, true]], $category);

        foreach ([
            [['max_price' => 200], [$low->id]],
            [['min_price' => 200], [$middle->id, $high->id]],
            [['min_price' => 300, 'max_price' => 500], [$middle->id, $high->id]],
            [['max_price' => 0], []],
            [['min_price' => 600], []],
            [['sort' => 'price_asc'], [$low->id, $middle->id, $high->id]],
            [['sort' => 'price_desc'], [$high->id, $middle->id, $low->id]],
        ] as [$filters, $expected]) {
            $this->get($this->url($route, $category, $filters))->assertOk()
                ->assertViewHas('products', function ($products) use ($expected, $filters) {
                    $ids = $products->modelKeys();
                    if (! isset($filters['sort'])) {
                        sort($ids);
                        sort($expected);
                    }

                    $this->assertSame($expected, $ids, json_encode($filters));

                    return true;
                });
        }
    }

    public function test_suspension_draft_and_archiving_hide_otherwise_purchasable_products(): void
    {
        $category = Category::create(['label' => 'Eligibility', 'icon' => 'shirt']);
        $suspended = $this->product('Filter Shirt Suspended', [[100, 2, true]], $category);
        $suspended->seller->update(['status' => 'suspended']);
        $draft = $this->product('Filter Shirt Draft', [[100, 2, true]], $category);
        $draft->update(['status' => 'draft']);
        $archived = $this->product('Filter Shirt Archived', [[100, 2, true]], $category);
        $archived->delete();
        foreach (['shop', 'search', 'category'] as $route) {
            $this->get($this->url($route, $category, []))->assertOk()->assertViewHas('products', fn ($products) => $products->isEmpty());
        }
        $this->getJson(route('buyer.search.suggestions', ['term' => 'Filter Shirt']))->assertJsonCount(0, 'popular');
        $this->get(route('buyer.products.show', $suspended))->assertNotFound();
    }

    public static function optionlessStates(): array
    {
        return [[true, 4], [false, 4], [true, 0]];
    }

    #[DataProvider('optionlessStates')]
    public function test_optionless_details_and_cards_use_default_variant_availability(bool $enabled, int $stock): void
    {
        $product = Product::factory()->create(['seller_id' => Seller::factory()->create()->id, 'name' => 'Optionless Shirt', 'price' => 250, 'stock_quantity' => 20]);
        $product->variants()->sole()->update(['is_available' => $enabled, 'stock_quantity' => $stock]);
        $canBuy = $enabled && $stock > 0;

        $this->get(route('buyer.products.index'))->assertViewHas('products', fn ($items) => $items->count() === ($canBuy ? 1 : 0));
        $response = $this->get(route('buyer.products.show', $product))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame($canBuy ? 0 : 1, $xpath->query('//*[@id="pdp-add-cart"]/@disabled')->length);
        $this->assertSame($canBuy ? 0 : 1, $xpath->query('//*[@id="pdp-buy-now"]/@disabled')->length);
        $response->assertSee($canBuy ? '4 unit(s) available' : 'Unavailable');

        $html = (string) $this->view('Components.buyer.product-card', ['product' => $product->fresh()->load(['variants', 'seller', 'images']), 'wishlistIds' => collect()]);
        $this->assertStringContainsString($canBuy ? $stock.' in stock' : 'Out of stock', $html);
        if (! $canBuy) {
            $this->assertStringContainsString('disabled', $html);
        }
        $this->assertSame(20, $product->fresh()->stock_quantity);
    }

    public function test_zero_price_and_missing_variants_are_handled_without_rollup_fallback(): void
    {
        $free = $this->product('Filter Shirt Free', [[0, 2, true]]);
        $missing = Product::factory()->create(['seller_id' => Seller::factory()->create()->id, 'stock_quantity' => 50, 'price' => 9]);
        $missing->variants()->delete();

        $this->get(route('buyer.products.index', ['max_price' => 0]))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->modelKeys() === [$free->id]);
        $this->get(route('buyer.products.show', $missing))->assertOk()->assertSee('Unavailable')->assertSee('Out of Stock');
        $this->assertTrue($missing->fresh()->buyerIsOutOfStock());
        $this->assertSame(50, $missing->fresh()->stock_quantity);
    }

    public function test_shop_pagination_counts_only_eligible_products_and_preserves_price_filters(): void
    {
        $category = Category::create(['label' => 'Pages', 'icon' => 'shirt']);
        $ids = [];
        for ($i = 0; $i < 13; $i++) {
            $ids[] = $this->product('Page Shirt '.$i, [[1, 5, false], [300, 2, true]], $category)->id;
        }
        $this->product('Page Shirt Disabled', [[1, 10, false]], $category);

        $this->get(route('buyer.products.index', ['min_price' => 300, 'max_price' => 300, 'sort' => 'price_asc', 'page' => 2]))
            ->assertOk()->assertViewHas('products', function ($products) use ($ids) {
                $this->assertSame(13, $products->total());
                $this->assertSame([$ids[12]], $products->modelKeys());
                $this->assertStringContainsString('min_price=300', $products->previousPageUrl());
                $this->assertStringContainsString('max_price=300', $products->previousPageUrl());
                $this->assertStringContainsString('sort=price_asc', $products->previousPageUrl());

                return true;
            });
    }

    public function test_catalog_query_count_is_bounded_as_result_count_grows(): void
    {
        $category = Category::create(['label' => 'Query Budget', 'icon' => 'shirt']);
        $this->product('Budget Shirt', [[1, 10, false], [300, 4, true]], $category);
        $this->get(route('buyer.products.index'))->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('buyer.products.index'))->assertOk();
        $single = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 7; $i++) {
            $this->product('Budget Shirt '.$i, [[1, 10, false], [300, 4, true]], $category);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('buyer.products.index'))->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($single, $many);
    }
}
