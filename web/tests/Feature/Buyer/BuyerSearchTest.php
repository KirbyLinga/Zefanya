<?php

namespace Tests\Feature\Buyer;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Services\Buyer\CatalogSearch;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** BUY-SRCH-003 to 009. Both pages are checked: the navbar searches /buyer/products. */
class BuyerSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Seller $seller;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Cache::flush();
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->category = Category::create(['label' => 'Accessories', 'icon' => 'shirt']);
    }

    private function product(string $name, float $price = 150.00, array $attributes = []): Product
    {
        return Product::factory()->create($attributes + [
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'name' => $name,
            'description' => 'Plain words only',
            'price' => $price,
            'stock_quantity' => 8,
            'status' => 'active',
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return [
            'navbar destination' => ['buyer.products.index'],
            'search page' => ['buyer.search.index'],
        ];
    }

    /** @return list<int> */
    private function ids(string $route, array $query): array
    {
        return $this->get(route($route, $query))->assertOk()->viewData('products')->pluck('id')->all();
    }

    #[DataProvider('pages')]
    public function test_srch_trims_collapses_spaces_and_ignores_case(string $route): void
    {
        $scarf = $this->product('Silk Scarf');
        $this->product('Leather Belt');

        foreach (['  silk scarf  ', 'SILK SCARF', 'sIlK    sCaRf', "\tsilk\nscarf "] as $term) {
            $this->assertSame([$scarf->id], $this->ids($route, ['q' => $term]), json_encode($term));
        }
    }

    #[DataProvider('pages')]
    public function test_srch_words_match_in_any_order(string $route): void
    {
        $scarf = $this->product('Silk Scarf');

        $this->assertSame([$scarf->id], $this->ids($route, ['q' => 'scarf silk']));
        $this->assertSame([], $this->ids($route, ['q' => 'silk belt']));
    }

    #[DataProvider('pages')]
    public function test_srch_wildcards_are_searched_literally(string $route): void
    {
        $this->product('Silk Scarf');
        $discount = $this->product('Sale 50% Tag');
        $underscore = $this->product('Item_one');

        $this->assertSame([], $this->ids($route, ['q' => '%%']));
        $this->assertSame([$discount->id], $this->ids($route, ['q' => '50%']));
        $this->assertSame([$underscore->id], $this->ids($route, ['q' => 'item_one']));
        $this->assertSame([], $this->ids($route, ['q' => 'ite__one']));
    }

    #[DataProvider('pages')]
    public function test_srch_array_input_does_not_crash(string $route): void
    {
        $this->product('Silk Scarf');

        $this->get(route($route, ['q' => ['silk'], 'min_price' => ['1'], 'max_price' => ['x'], 'sort' => ['price_asc']]))->assertOk();
        $this->getJson(route('buyer.search.suggestions', ['term' => ['silk']]))->assertOk()->assertJsonCount(0, 'popular');
    }

    public function test_srch_suggestions_use_the_same_matching(): void
    {
        $this->product('Silk Scarf');

        $this->getJson(route('buyer.search.suggestions', ['term' => '  SCARF  silk ']))->assertOk()->assertJsonCount(1, 'popular');
        $this->getJson(route('buyer.search.suggestions', ['term' => '%']))->assertOk()->assertJsonCount(0, 'popular');
    }

    #[DataProvider('pages')]
    public function test_srch_typo_gets_a_did_you_mean_link_that_finds_the_product(string $route): void
    {
        $scarf = $this->product('Silk Scarf');

        $page = $this->get(route($route, ['q' => 'skarf']))->assertOk();

        $this->assertSame('scarf', $page->viewData('suggestion'));
        $page->assertSee('Did you mean')
            ->assertSee(route($route, ['q' => 'scarf']), false);

        $this->assertSame([$scarf->id], $this->ids($route, ['q' => 'scarf']));
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function typos(): array
    {
        return [
            'one wrong word of two' => ['silk skarf', 'silk scarf'],
            'capitals' => ['SKARF', 'scarf'],
            'dropped letter' => ['leathr', 'leather'],
            'two wrong words' => ['slik skarf', 'silk scarf'],
            'nothing close' => ['qzxvwkj', null],
            'a correct word is left alone' => ['scarf', null],
            'too short to correct' => ['zz', null],
            'words that never share a product' => ['silk leather', null],
        ];
    }

    #[DataProvider('typos')]
    public function test_srch_suggest_rules(string $term, ?string $expected): void
    {
        $this->product('Silk Scarf');
        $this->product('Leather Belt');

        $this->assertSame($expected, CatalogSearch::suggest($term));
    }

    public function test_srch_suggestions_never_reveal_hidden_products(): void
    {
        $this->product('Silk Scarf');
        $this->product('Secretdraft Coat', attributes: ['status' => 'draft']);
        $this->product('Restrictedthing Hat', attributes: ['restriction_reason' => 'Under review']);
        $this->product('Suspendedshop Bag', attributes: ['seller_id' => Seller::factory()->create(['status' => 'suspended'])->id]);

        foreach (['secretdraf', 'restrictedthin', 'suspendedsho'] as $typo) {
            $this->assertNull(CatalogSearch::suggest($typo), $typo);
        }
    }

    public function test_srch_no_suggestion_while_filters_explain_the_empty_page(): void
    {
        $this->product('Silk Scarf');

        foreach (['buyer.products.index', 'buyer.search.index'] as $route) {
            $this->assertNull($this->get(route($route, ['q' => 'skarf', 'min_price' => 1]))->viewData('suggestion'));
            $this->assertNull($this->get(route($route, ['q' => 'skarf', 'category' => $this->category->id]))->viewData('suggestion'));
        }
    }

    public function test_srch_empty_states_say_what_to_do(): void
    {
        $this->product('Silk Scarf');

        $this->get(route('buyer.search.index'))->assertOk()->assertSee('Enter a search term to get started.');
        $this->get(route('buyer.search.index', ['q' => 'x']))->assertOk()->assertSee('Enter at least 2 characters');
        $this->get(route('buyer.search.index', ['q' => 'qzxvwkj']))->assertOk()
            ->assertSee('No results found')
            ->assertSee('Check the spelling')
            ->assertSee('qzxvwkj')
            ->assertDontSee('Did you mean');
        $this->get(route('buyer.products.index', ['q' => 'qzxvwkj']))->assertOk()
            ->assertSee('No products found')
            ->assertSee('Check the spelling');
        $this->get(route('buyer.products.index', ['q' => 'scarf', 'min_price' => 9000]))->assertOk()
            ->assertSee('this price range');
    }

    public function test_srch_search_terms_are_escaped_on_the_page(): void
    {
        foreach (['buyer.products.index', 'buyer.search.index'] as $route) {
            $this->get(route($route, ['q' => '<script>alert(1)</script>']))->assertOk()
                ->assertDontSee('<script>alert(1)</script>', false);
        }
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badPrices(): array
    {
        return [
            'min above max' => [['min_price' => 500, 'max_price' => 100], 'minimum price cannot be higher'],
            'negative min' => [['min_price' => -1], 'cannot be negative'],
            'negative max' => [['max_price' => -50], 'cannot be negative'],
            'not a number' => [['min_price' => 'abc'], 'as numbers'],
            'not a number max' => [['min_price' => 1, 'max_price' => 'lots'], 'as numbers'],
        ];
    }

    /** @param array<string, mixed> $prices */
    #[DataProvider('badPrices')]
    public function test_srch_bad_prices_are_rejected_with_a_message_and_not_applied(array $prices, string $message): void
    {
        $cheap = $this->product('Silk Scarf', 150.00);
        $dear = $this->product('Silk Robe', 900.00);

        foreach (['buyer.products.index', 'buyer.search.index'] as $route) {
            $page = $this->get(route($route, ['q' => 'silk'] + $prices))->assertOk()->assertSee($message);

            $this->assertEqualsCanonicalizing([$cheap->id, $dear->id], $page->viewData('products')->pluck('id')->all());
            $this->assertNotNull($page->viewData('priceError'));
        }
    }

    public function test_srch_valid_prices_are_applied_including_equal_bounds_and_zero(): void
    {
        $cheap = $this->product('Silk Scarf', 150.00);
        $dear = $this->product('Silk Robe', 900.00);

        foreach (['buyer.products.index', 'buyer.search.index'] as $route) {
            $this->assertSame([$cheap->id], $this->ids($route, ['q' => 'silk', 'min_price' => 150, 'max_price' => 150]));
            $this->assertSame([$dear->id], $this->ids($route, ['q' => 'silk', 'min_price' => 500]));
            $this->assertSame([$cheap->id], $this->ids($route, ['q' => 'silk', 'min_price' => 0, 'max_price' => 500]));

            $this->assertNull($this->get(route($route, ['q' => 'silk', 'min_price' => 0, 'max_price' => 500]))->assertOk()->viewData('priceError'));
        }
    }

    public function test_srch_both_pages_have_a_price_form_that_keeps_other_filters(): void
    {
        $this->product('Silk Scarf');

        foreach (['buyer.products.index', 'buyer.search.index'] as $route) {
            $this->get(route($route, ['q' => 'silk', 'sort' => 'price_asc', 'seller' => $this->seller->id]))->assertOk()
                ->assertSee('name="min_price"', false)
                ->assertSee('name="max_price"', false)
                ->assertSee('<input type="hidden" name="q" value="silk">', false)
                ->assertSee('<input type="hidden" name="sort" value="price_asc">', false)
                ->assertSee('<input type="hidden" name="seller" value="'.$this->seller->id.'">', false)
                ->assertDontSee('name="page"', false);
        }
    }

    #[DataProvider('pages')]
    public function test_srch_pages_never_repeat_or_skip_a_product_when_timestamps_tie(string $route): void
    {
        $ids = [];
        foreach (range(1, 30) as $i) {
            $ids[] = $this->product("Silk Item {$i}", 100.00, ['created_at' => '2026-10-01 10:00:00'])->id;
        }

        foreach (['relevance', 'newest', 'oldest', 'price_asc', 'price_desc'] as $sort) {
            $seen = [];
            foreach ([1, 2, 3] as $page) {
                $seen = array_merge($seen, $this->ids($route, ['q' => 'silk', 'sort' => $sort, 'page' => $page]));
            }

            $this->assertCount(30, $seen, $sort);
            $this->assertEqualsCanonicalizing($ids, $seen, $sort);
        }
    }

    #[DataProvider('pages')]
    public function test_srch_page_links_keep_the_search_and_drop_a_rejected_price(string $route): void
    {
        foreach (range(1, 13) as $i) {
            $this->product("Silk Item {$i}");
        }

        $good = (string) $this->get(route($route, ['q' => 'silk', 'min_price' => 1]))->assertOk()->viewData('products')->nextPageUrl();
        $this->assertStringContainsString('q=silk', $good);
        $this->assertStringContainsString('min_price=1', $good);

        $bad = (string) $this->get(route($route, ['q' => 'silk', 'min_price' => -1]))->assertOk()->viewData('products')->nextPageUrl();
        $this->assertStringContainsString('q=silk', $bad);
        $this->assertStringNotContainsString('min_price', $bad);
    }

    #[DataProvider('pages')]
    public function test_srch_a_page_past_the_end_offers_a_way_back(string $route): void
    {
        $this->product('Silk Scarf');

        $this->get(route($route, ['q' => 'silk', 'page' => 99]))->assertOk()
            ->assertSee('past the end')
            ->assertSee('Go to the first page');
    }
}
