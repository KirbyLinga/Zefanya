<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Seller\Seller;
use Database\Seeders\DemoCatalogueSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The demo catalogue has to satisfy the *endpoint's* rules, not merely exist.
 *
 * A seeder can look right and still produce a database the app cannot see: an
 * unapproved seller, a status typo'd to `draft`, a category label that matches no
 * row, a photo path that is not a URL. Each of those turns a filled database into
 * an empty-looking app, and each is asserted here rather than hoped for.
 */
class DemoCatalogueSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        $this->seed(DemoCatalogueSeeder::class);

        return $this->getJson(route('api.products.index'))->assertOk()->json('data');
    }

    public function test_the_demo_catalogue_is_visible_to_the_app(): void
    {
        $this->assertCount(DemoCatalogueSeeder::VISIBLE_PRODUCTS, $this->catalogue());
    }

    public function test_every_demo_product_has_a_category_a_shop_and_a_price(): void
    {
        foreach ($this->catalogue() as $row) {
            // A category label that matches no row silently becomes
            // "Uncategorised", so this is what catches a typo in the seeder.
            $this->assertNotSame('Uncategorised', $row['category'], $row['name'].' must resolve to a category row');
            $this->assertNotSame('', $row['seller']['name'], $row['name'].' must name its shop');
            $this->assertGreaterThan(0, $row['price_minor'], $row['name'].' must cost something');
        }
    }

    public function test_every_photo_is_a_url_the_app_can_fetch(): void
    {
        foreach ($this->catalogue() as $row) {
            $this->assertNotEmpty($row['images'], $row['name'].' needs a cover photo');

            foreach ($row['images'] as $url) {
                // `Image.network` cannot fetch a relative path, and this is the
                // assertion that would have caught Storage::url() answering
                // `/storage/...` for an uploaded photo.
                $this->assertMatchesRegularExpression(
                    '#^https?://#',
                    $url,
                    $row['name'].' hands the app a photo it cannot load: '.$url,
                );
            }
        }
    }

    public function test_at_least_one_product_carries_a_gallery(): void
    {
        $withSeveralPhotos = array_filter(
            $this->catalogue(),
            fn (array $row): bool => count($row['images']) > 1,
        );

        $this->assertNotEmpty(
            $withSeveralPhotos,
            'the gallery and the stacked showcase need a product with more than one photo',
        );
    }

    public function test_a_sold_out_product_is_listed_and_says_so(): void
    {
        $windChime = collect($this->catalogue())->firstWhere('name', 'Capiz Shell Wind Chime');

        $this->assertNotNull($windChime, 'a sold-out product must not vanish from the catalogue');
        $this->assertFalse($windChime['in_stock']);
    }

    public function test_the_rows_that_must_stay_hidden_do(): void
    {
        $names = collect($this->catalogue())->pluck('name');

        $this->assertNotContains('Prototype Weave Sample', $names, 'a draft product must not be listed');
        $this->assertNotContains('Uling Starter Bag', $names, "an unapproved seller's product must not be listed");

        // The single read agrees with the list, and 404s rather than 403s: the app
        // is told "this was never here", which is what a shop window says about a
        // draft, and it cannot be used to probe which ids exist.
        $draft = Product::query()->where('name', 'Prototype Weave Sample')->sole();

        $this->getJson(route('api.products.show', $draft))->assertNotFound();
    }

    public function test_the_demo_shops_are_verified_and_approved_on_the_record_not_just_in_status(): void
    {
        $this->catalogue();

        // `email_verified_at` and `approved_at` are not fillable, so a seeder that
        // passes them through `updateOrCreate` silently writes nulls: the shops
        // would look approved to the catalogue query and unverified to every other
        // screen in the app.
        $approved = Seller::query()->where('status', 'approved')->get();

        $this->assertCount(5, $approved);

        foreach ($approved as $seller) {
            $this->assertNotNull($seller->email_verified_at, $seller->business_name.' is approved but never verified its email');
            $this->assertNotNull($seller->approved_at, $seller->business_name.' is approved with no approval timestamp');
        }

        // And the one shop that is still waiting keeps its own story: verified,
        // not approved.
        $pending = Seller::query()->where('business_name', 'Bagong Uling Supplies')->sole();

        $this->assertSame('pending_approval', $pending->status);
        $this->assertNotNull($pending->email_verified_at);
        $this->assertNull($pending->approved_at);
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        $this->catalogue();

        $products = Product::count();
        $images = ProductImage::count();
        $sellers = Seller::count();

        $this->seed(DemoCatalogueSeeder::class);

        $this->assertSame($products, Product::count(), 're-seeding duplicated products');
        $this->assertSame($images, ProductImage::count(), 're-seeding duplicated photos');
        $this->assertSame($sellers, Seller::count(), 're-seeding duplicated shops');
    }
}
