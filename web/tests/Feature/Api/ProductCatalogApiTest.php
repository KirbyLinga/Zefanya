<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET /api/products` and `GET /api/products/{product}` — the app's catalogue.
 *
 * What these are for, beyond "it returns a list": the app's product page is
 * driven by six fields, and every one of them is a chance to be quietly wrong —
 * an id that arrives as a number breaks the wishlist's set membership, a price in
 * pesos renders as a hundred times too much, an unordered image list puts the
 * wrong photo on the cover.
 *
 * The visibility rules get the most attention, because they are the only ones
 * with a *person* on the other side: a draft product or an unapproved seller's
 * product must not be listed, must not be openable, and must not be
 * distinguishable from a product that never existed.
 */
class ProductCatalogApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** A category row — the migration seeds fourteen, so this reads one. */
    private function category(string $label = 'Home and Garden'): Category
    {
        return Category::query()->where('label', $label)->sole();
    }

    private function approvedSeller(string $businessName = 'Loom & Larder'): Seller
    {
        return Seller::factory()->create([
            'business_name' => $businessName,
            'status' => 'approved',
        ]);
    }

    /** @return array{0: Product, 1: Seller} */
    private function product(array $overrides = []): array
    {
        $seller = $this->approvedSeller();

        $product = Product::factory()->create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => $this->category()->id,
            'name' => 'Handwoven Rattan Tote',
            'price' => 1250.75,
            'status' => 'active',
        ], $overrides));

        return [$product, $seller];
    }

    private function list(): TestResponse
    {
        return $this->getJson(route('api.products.index'));
    }

    // ── What one product looks like on the wire ──────────────────────────────

    public function test_it_returns_the_envelope_the_apps_helpers_read(): void
    {
        [$product] = $this->product();

        $this->list()
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'name', 'description', 'category', 'price_minor', 'in_stock', 'seller' => ['name'], 'images'],
                ],
            ])
            ->assertJsonPath('data.0.name', $product->name);
    }

    public function test_the_id_is_a_string_so_the_apps_id_sets_still_match(): void
    {
        [$product] = $this->product();

        $id = $this->list()->json('data.0.id');

        // The app keys its wishlist, recently-viewed rail and order joins on
        // string ids that come from the mock catalogue's 'p1'/'p2'. A JSON number
        // here would make a live product un-addable to a wishlist that already
        // holds a mock one.
        $this->assertIsString($id);
        $this->assertSame((string) $product->id, $id);
    }

    public function test_the_price_crosses_as_centavos(): void
    {
        $this->product(['price' => 1250.75]);

        // 1250.75 pesos must arrive as 125075, not 1250.75. The app multiplies
        // nothing on the way in — `money.dart` reads minor units and divides once
        // — so this field being in pesos would render as ₱125,075.00.
        $this->list()->assertJsonPath('data.0.price_minor', 125075);
    }

    public function test_a_whole_peso_price_keeps_its_two_centavo_digits(): void
    {
        $this->product(['price' => 60]);

        $this->list()->assertJsonPath('data.0.price_minor', 6000);
    }

    public function test_the_category_is_its_label_not_an_id(): void
    {
        $this->product();

        $this->list()->assertJsonPath('data.0.category', 'Home and Garden');
    }

    public function test_the_sellers_shop_name_is_what_names_the_product(): void
    {
        $this->product(['name' => 'Rattan Tote']);
        $ceramics = $this->approvedSeller('Tahanan Ceramics');
        Product::factory()->create([
            'seller_id' => $ceramics->id,
            'category_id' => $this->category()->id,
            'name' => 'Stoneware Mug',
            'status' => 'active',
        ]);

        $names = collect($this->list()->json('data'))->pluck('seller.name');

        $this->assertContains('Loom & Larder', $names);
        $this->assertContains('Tahanan Ceramics', $names);
    }

    public function test_the_cover_photo_comes_first_even_when_upload_order_disagrees(): void
    {
        Storage::fake('public');
        [$product] = $this->product();

        // Upload order is not display order: the second image is flagged primary.
        ProductImage::factory()->create([
            'product_id' => $product->id, 'path' => 'products/a.jpg', 'is_primary' => false, 'sort_order' => 0,
        ]);
        ProductImage::factory()->create([
            'product_id' => $product->id, 'path' => 'products/b.jpg', 'is_primary' => true, 'sort_order' => 1,
        ]);

        $images = $this->getJson(route('api.products.show', $product))->json('data.images');

        $this->assertCount(2, $images);
        $this->assertStringContainsString('products/b.jpg', $images[0]);
        $this->assertStringContainsString('products/a.jpg', $images[1]);
    }

    public function test_a_product_with_no_flagged_cover_still_has_one(): void
    {
        Storage::fake('public');
        [$product] = $this->product();

        // The primary flag is set on upload, not by a constraint, so a row without
        // it is possible. The gallery's first frame must still be a real photo.
        ProductImage::factory()->create([
            'product_id' => $product->id, 'path' => 'products/only.jpg', 'is_primary' => false, 'sort_order' => 0,
        ]);

        $images = $this->getJson(route('api.products.show', $product))->json('data.images');

        $this->assertCount(1, $images);
        $this->assertStringContainsString('products/only.jpg', $images[0]);
    }

    public function test_a_product_with_no_photos_says_so_rather_than_inventing_one(): void
    {
        [$product] = $this->product();

        // An empty list, not a stock photo: the app falls back to a labelled
        // placeholder of its own, and a URL invented here would be nobody's photo.
        $this->assertSame([], $this->getJson(route('api.products.show', $product))->json('data.images'));
    }

    public function test_a_photo_that_is_already_a_url_is_not_run_through_the_disk(): void
    {
        [$product] = $this->product();

        // A seeded or externally hosted photo is a whole URL already. Wrapping it
        // in the disk's URL builder would produce `/storage/https://…`, which is
        // not a URL anyone can fetch.
        ProductImage::factory()->create([
            'product_id' => $product->id,
            'path' => 'https://picsum.photos/seed/demo-0/1000/1000',
            'is_primary' => true,
        ]);

        $images = $this->getJson(route('api.products.show', $product))->json('data.images');

        $this->assertSame(['https://picsum.photos/seed/demo-0/1000/1000'], $images);
    }

    public function test_an_uncategorised_product_still_reads_as_a_category(): void
    {
        [$product] = $this->product(['category_id' => null]);

        $this->assertSame('Uncategorised', $this->getJson(route('api.products.show', $product))->json('data.category'));
    }

    // ── Who may see what ─────────────────────────────────────────────────────

    public function test_a_draft_product_is_neither_listed_nor_openable(): void
    {
        [$product] = $this->product(['status' => 'draft']);

        $this->list()->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('api.products.show', $product))->assertNotFound();
    }

    public function test_an_inactive_product_is_neither_listed_nor_openable(): void
    {
        [$product] = $this->product(['status' => 'inactive']);

        $this->list()->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('api.products.show', $product))->assertNotFound();
    }

    public function test_an_unapproved_sellers_product_is_neither_listed_nor_openable(): void
    {
        [$product] = $this->product();
        $product->seller->update(['status' => 'pending_approval']);

        // Not a 403: a shop window says "not here" about an item it will not show,
        // and a 403 would confirm the id exists.
        $this->list()->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('api.products.show', $product))->assertNotFound();
    }

    public function test_a_sold_out_product_is_still_listed_and_says_so(): void
    {
        [$product] = $this->product(['stock_quantity' => 0]);

        // Deliberately *not* hidden: an item that vanishes from a catalogue looks
        // like a bug, and the app has no stock UI to explain an absence with. The
        // flag carries the fact instead.
        $this->list()->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.in_stock', false);

        $this->assertTrue($product->fresh()->isOutOfStock());
    }

    public function test_a_deleted_product_is_not_openable(): void
    {
        [$product] = $this->product();
        $product->delete();

        $this->getJson(route('api.products.show', $product))->assertNotFound();
    }

    public function test_the_catalogue_needs_no_token(): void
    {
        $this->product();

        // No `Authorization` header anywhere in this class, by design: browsing is
        // not a per-buyer act, and the web's own catalogue pages sit outside their
        // buyer-auth group for the same reason.
        $this->list()->assertOk()->assertJsonCount(1, 'data');
    }

    // ── The three filters the app can ask for ────────────────────────────────

    public function test_it_filters_by_category_label(): void
    {
        $this->product(['name' => 'Rattan Tote']);
        Product::factory()->create([
            'seller_id' => $this->approvedSeller()->id,
            'category_id' => $this->category('Pet Supplies')->id,
            'name' => 'Rope Leash',
            'status' => 'active',
        ]);

        $response = $this->getJson(route('api.products.index', ['category' => 'Pet Supplies']));

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Rope Leash', $response->json('data.0.name'));
    }

    public function test_an_unknown_category_is_an_empty_list_not_an_error(): void
    {
        $this->product();

        // The app renders an empty state for an empty list and an error-with-retry
        // for a throw. A filter that matches nothing is the first of those — and
        // the app's own mock catalogue has categories the server does not
        // ('Fashion'), so this is a shape the app can really produce.
        $this->getJson(route('api.products.index', ['category' => 'Fashion']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_searches_by_name(): void
    {
        $this->product(['name' => 'Handwoven Rattan Tote']);
        Product::factory()->create([
            'seller_id' => $this->approvedSeller()->id,
            'category_id' => $this->category()->id,
            'name' => 'Linen Throw',
            'status' => 'active',
        ]);

        $response = $this->getJson(route('api.products.index', ['q' => 'rattan']));

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Handwoven Rattan Tote', $response->json('data.0.name'));
    }

    public function test_search_also_matches_the_category_name(): void
    {
        $this->product(['category_id' => $this->category('Pet Supplies')->id, 'name' => 'Rope Leash']);

        // The same two fields the mock catalogue matches, so a query does not
        // return different products depending on which mode the app is in.
        $this->getJson(route('api.products.index', ['q' => 'pet']))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_does_not_match_the_description(): void
    {
        $this->product(['name' => 'Rope Leash', 'description' => 'Made from rattan offcuts']);

        // Name and category only: a description match makes a two-word query
        // return nearly everything, which reads as broken rather than thorough.
        $this->getJson(route('api.products.index', ['q' => 'rattan']))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_filters_by_shop_name(): void
    {
        $this->product(['name' => 'Rattan Tote']);
        $ceramics = $this->approvedSeller('Tahanan Ceramics');
        Product::factory()->create([
            'seller_id' => $ceramics->id,
            'category_id' => $this->category()->id,
            'name' => 'Stoneware Mug',
            'status' => 'active',
        ]);

        $response = $this->getJson(route('api.products.index', ['seller' => 'Tahanan Ceramics']));

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Stoneware Mug', $response->json('data.0.name'));
    }

    public function test_no_filters_returns_every_visible_product_newest_first(): void
    {
        $this->product(['name' => 'First']);
        Product::factory()->create([
            'seller_id' => $this->approvedSeller()->id,
            'category_id' => $this->category()->id,
            'name' => 'Second',
            'status' => 'active',
        ]);

        $response = $this->list()->assertOk()->assertJsonCount(2, 'data');

        // An unbounded list still needs a stable order, or two identical requests
        // can render in two different orders.
        $this->assertSame('Second', $response->json('data.0.name'));
    }
}
