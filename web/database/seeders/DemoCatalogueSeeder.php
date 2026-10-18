<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A browsable demo catalogue, so a live build has something to show.
 *
 * ## Why this exists
 *
 * The catalogue endpoints are real and the app can read them, but the dev
 * database held **0 sellers and 0 products**: live mode was correct and empty,
 * which is indistinguishable from broken. This fills it with shops and goods that
 * the app can actually render, and nothing else — no orders, no reviews, no
 * counters. Nothing here claims anything the server does not know (see the
 * project's no-invented-content rule): names, prices, stock and photos are the
 * only facts, because they are the only columns.
 *
 * ## Re-runnable, deliberately
 *
 * Sellers are keyed by email and products by slug, so seeding twice updates
 * rather than duplicates — which matters because this is a *dev* tool a person
 * will run again after changing a price or adding a product.
 *
 * ## The photos are the one thing that is not real
 *
 * `product_images.path` holds a Lorem Picsum URL, which is the same
 * placeholder-image service the app's own fallback already uses
 * (`Product.photos` → `placeholderPhoto()`), so a live catalogue looks like the
 * mock one instead of an empty gallery. A real photo arrives by going through the
 * seller's own upload flow (`routes/seller.php`), which stores a path; the
 * resource distinguishes the two by asking whether the value is already a URL.
 *
 * ## Three products are seeded that must NOT be visible
 *
 * A draft product, an inactive one, and a product belonging to a seller who is
 * still `pending_approval`. They are here because the visibility rule is the one
 * thing in the catalogue with a person on the other side, and a demo database
 * that only contains happy rows cannot tell you the rule is working. The test
 * that accompanies this seeder asserts the list returns the active twelve.
 */
class DemoCatalogueSeeder extends Seeder
{
    /**
     * The demo shops. Every one is approved and email-verified, because an
     * unapproved seller's products are invisible by rule — a demo catalogue made
     * of invisible products would look exactly like an empty one.
     *
     * @var list<array<string, mixed>>
     */
    private const SELLERS = [
        [
            'business_name' => 'Loom & Larder',
            'category' => 'Home and Garden',
            'email' => 'loom-and-larder@zefanya.test',
            'first_name' => 'Marisol',
            'last_name' => 'Aguirre',
            'sex' => 'female',
            'province' => 'Cebu',
            'municipality' => 'Cebu City',
            'barangay' => 'Lahug',
            'street' => 'Sanciangko Street',
            'house_number' => '12-A',
            'maker_note' => 'Every piece leaves this workshop with one rule: it has to be useful the day after you buy it.',
        ],
        [
            'business_name' => 'Tahanan Ceramics',
            'category' => 'Home and Garden',
            'email' => 'tahanan-ceramics@zefanya.test',
            'first_name' => 'Elias',
            'last_name' => 'Monteverde',
            'sex' => 'male',
            'province' => 'Iloilo',
            'municipality' => 'Iloilo City',
            'barangay' => 'Molo',
            'street' => 'Avanceña Street',
            'house_number' => '7',
            'maker_note' => 'I fire everything in a kiln I built from second-hand bricks. No two pieces come out the same color.',
        ],
        [
            'business_name' => 'Hiraya Weaves',
            'category' => "Women's Apparel",
            'email' => 'hiraya-weaves@zefanya.test',
            'first_name' => 'Dalisay',
            'last_name' => 'Bautista',
            'sex' => 'female',
            'province' => 'Abra',
            'municipality' => 'Bangued',
            'barangay' => 'Calaba',
            'street' => 'Magallanes Street',
            'house_number' => '45',
            'maker_note' => 'My grandmother taught me the loom when I was nine. I still use her threading pattern.',
        ],
        [
            'business_name' => 'Kape ni Lolo',
            'category' => 'Food and Gourmet',
            'email' => 'kape-ni-lolo@zefanya.test',
            'first_name' => 'Ruben',
            'last_name' => 'Dumaguing',
            'sex' => 'male',
            'province' => 'Benguet',
            'municipality' => 'La Trinidad',
            'barangay' => 'Pico',
            'street' => 'Km 5 Halsema Highway',
            'house_number' => '3',
            'maker_note' => 'We pick at 4 AM before the fog lifts. The cold keeps the beans dense and the cup clean.',
        ],
        [
            'business_name' => 'Alon Botanicals',
            'category' => 'Health and Beauty',
            'email' => 'alon-botanicals@zefanya.test',
            'first_name' => 'Teresa',
            'last_name' => 'Villamor',
            'sex' => 'female',
            'province' => 'Bohol',
            'municipality' => 'Dauis',
            'barangay' => 'Biking',
            'street' => 'Purok 4',
            'house_number' => '21',
            'maker_note' => null,
        ],
        // Not approved: exists so the visibility rule has something to hide.
        [
            'business_name' => 'Bagong Uling Supplies',
            'category' => 'Furniture and Office Equipment',
            'email' => 'bagong-uling@zefanya.test',
            'first_name' => 'Nestor',
            'last_name' => 'Palma',
            'sex' => 'male',
            'province' => 'Quezon',
            'municipality' => 'Lucban',
            'barangay' => 'Poblacion',
            'street' => 'Quezon Avenue',
            'house_number' => '88',
            'maker_note' => null,
            'status' => 'pending_approval',
        ],
    ];

    /**
     * How many products `GET /api/products` must return once this seeder has run.
     *
     * Thirteen of the fifteen rows below: one is a draft and one belongs to a
     * seller who is still waiting on admin approval. `DemoCatalogueSeederTest`
     * asserts against this constant, so the number a reader sees here and the
     * number the app is promised cannot drift apart.
     */
    public const VISIBLE_PRODUCTS = 13;

    /**
     * The demo goods.
     *
     * `photos` is how many placeholder photos to attach — the gallery and the
     * stacked showcase both need more than one photo to exist at all, so most
     * rows get two to four and a live build exercises them.
     *
     * @var list<array<string, mixed>>
     */
    private const PRODUCTS = [
        ['shop' => 'Loom & Larder', 'category' => 'Home and Garden', 'name' => 'Handwoven Rattan Tote', 'price' => 1250.75, 'stock' => 18, 'photos' => 4,
            'description' => 'Handwoven in Cebu from province-grown rattan; flat woven base, leather handle.'],
        ['shop' => 'Loom & Larder', 'category' => 'Home and Garden', 'name' => 'Capiz Shell Wind Chime', 'price' => 680.00, 'stock' => 0, 'photos' => 3,
            'description' => 'Capiz shells on waxed cotton, in four graduated tiers.'],
        ['shop' => 'Loom & Larder', 'category' => 'Furniture and Office Equipment', 'name' => 'Kamagong Stool', 'price' => 3800.00, 'stock' => 3, 'photos' => 3,
            'description' => 'Solid kamagong with mortise-and-tenon joinery, finished in beeswax and linseed oil.'],
        ['shop' => 'Tahanan Ceramics', 'category' => 'Home and Garden', 'name' => 'Terracotta Planter Trio', 'price' => 980.50, 'stock' => 7, 'photos' => 3,
            'description' => 'Three unglazed planters thrown by hand, with drain holes and saucers.'],
        ['shop' => 'Tahanan Ceramics', 'category' => 'Home and Garden', 'name' => 'Stoneware Rice Bowl Set', 'price' => 1450.00, 'stock' => 12, 'photos' => 4,
            'description' => 'Four stoneware bowls in a wood-ash glaze, each a slightly different depth.'],
        ['shop' => 'Hiraya Weaves', 'category' => "Women's Apparel", 'name' => 'Inabel Wrap Skirt', 'price' => 1650.00, 'stock' => 9, 'photos' => 3,
            'description' => 'Woven on a wooden loom in Abra, with a wide waist tie and hand-rolled hems.'],
        ['shop' => 'Hiraya Weaves', 'category' => "Women's Apparel", 'name' => 'Piña Silk Scarf', 'price' => 1200.00, 'stock' => 15, 'photos' => 2,
            'description' => 'Pineapple-leaf silk in an open weave, finished with a hand-knotted fringe.'],
        ['shop' => 'Hiraya Weaves', 'category' => "Women's Apparel", 'name' => "T'nalak Shawl", 'price' => 2950.00, 'stock' => 4, 'photos' => 3,
            'description' => 'Handwoven abaca in the T\'boli t\'nalak pattern, tie-dyed before the weave begins.'],
        ['shop' => 'Kape ni Lolo', 'category' => 'Food and Gourmet', 'name' => 'Benguet Single-Origin Coffee', 'price' => 520.00, 'stock' => 40, 'photos' => 2,
            'description' => 'Arabica grown in La Trinidad, washed, sun-dried and roasted in small batches. 250g bag.'],
        ['shop' => 'Kape ni Lolo', 'category' => 'Food and Gourmet', 'name' => 'Bagoong Balayan Jar', 'price' => 180.00, 'stock' => 25, 'photos' => 2,
            'description' => 'Fermented anchovy paste made the Batangas way, in a sealed glass jar. 250g.'],
        ['shop' => 'Kape ni Lolo', 'category' => 'Food and Gourmet', 'name' => 'Tablea Cacao Discs', 'price' => 340.00, 'stock' => 2, 'photos' => 3,
            'description' => 'Roasted, ground and moulded cacao discs, unsweetened. Ten to a pack.'],
        ['shop' => 'Alon Botanicals', 'category' => 'Health and Beauty', 'name' => 'Virgin Coconut Oil Soap', 'price' => 240.00, 'stock' => 60, 'photos' => 2,
            'description' => 'Cold-pressed coconut oil, cured six weeks, unscented. One 110g bar.'],
        ['shop' => 'Alon Botanicals', 'category' => 'Health and Beauty', 'name' => 'Ilang-Ilang Body Oil', 'price' => 760.00, 'stock' => 11, 'photos' => 3,
            'description' => 'Ilang-ilang distilled in Bohol, carried in sweet almond oil. 100ml amber bottle.'],
        // Hidden by its own status.
        ['shop' => 'Hiraya Weaves', 'category' => "Women's Apparel", 'name' => 'Prototype Weave Sample', 'price' => 250.00, 'stock' => 5, 'photos' => 1, 'status' => 'draft',
            'description' => 'A loom sample, kept for reference while the pattern is worked out.'],
        // Hidden by its seller's status; the product itself is active.
        ['shop' => 'Bagong Uling Supplies', 'category' => 'Furniture and Office Equipment', 'name' => 'Uling Starter Bag', 'price' => 420.00, 'stock' => 30, 'photos' => 2,
            'description' => 'Kiln-dried charcoal in a paper sack, for grilling or for the kalan.'],
    ];

    public function run(): void
    {
        $sellers = $this->seedSellers();

        foreach (self::PRODUCTS as $row) {
            $this->seedProduct($row, $sellers);
        }

        $this->command?->info(
            'Demo catalogue: '.count($sellers).' shops, '.count(self::PRODUCTS)
            .' products ('.self::VISIBLE_PRODUCTS.' visible to the app).'
        );
    }

    /**
     * Create or refresh the demo shops, keyed by email so re-seeding updates
     * instead of duplicating.
     *
     * @return array<string, Seller> Keyed by business name, which is what the
     *                               product rows refer to.
     */
    private function seedSellers(): array
    {
        $sellers = [];

        foreach (self::SELLERS as $shop) {
            $status = $shop['status'] ?? 'approved';

            $seller = Seller::updateOrCreate(
                ['email' => $shop['email']],
                [
                    'last_name' => $shop['last_name'],
                    'first_name' => $shop['first_name'],
                    'sex' => $shop['sex'],
                    // Hashed by the model's cast. The value is the same `password`
                    // every factory and test account in this project uses.
                    'password' => 'password',
                    // Eleven characters, as the column demands: '09' plus nine
                    // digits taken from the shop's own email, so a re-run keeps the
                    // same number instead of inventing a new one each time.
                    'contact_no' => '09'.str_pad((string) (abs(crc32($shop['email'])) % 1000000000), 9, '0', STR_PAD_LEFT),
                    'birthday' => '1979-06-14',
                    'age' => 47,
                    'address_mode' => 'manual',
                    'province_name' => $shop['province'],
                    'municipality_name' => $shop['municipality'],
                    'barangay_name' => $shop['barangay'],
                    'street' => $shop['street'],
                    'house_number' => $shop['house_number'],
                    'business_name' => $shop['business_name'],
                    'maker_note' => $shop['maker_note'] ?? null,
                    'line_of_business_id' => $this->categoryId($shop['category']),
                    // Both paths are NOT NULL. Nothing reads them: they are the
                    // seller's private uploads, and no demo account logs in.
                    'upload_id_path' => 'sellers/demo/'.Str::slug($shop['business_name']).'-id.jpg',
                    'business_permit_path' => 'sellers/demo/'.Str::slug($shop['business_name']).'-permit.jpg',
                    'status' => $status,
                ],
            );

            // Neither timestamp is fillable — the web flow writes them with
            // `forceFill` too (`Seller::markEmailVerified`) — so they are written
            // the same way here rather than made mass-assignable for a seeder's
            // sake. Every demo seller *has* confirmed their email, including the
            // unapproved one, whose status means "confirmed, waiting on admin"
            // rather than "never confirmed". An existing timestamp is kept, so
            // re-seeding never rewrites when an account was verified.
            $seller->forceFill([
                'email_verified_at' => $seller->email_verified_at ?? now(),
                'approved_at' => $status === 'approved' ? ($seller->approved_at ?? now()) : null,
            ])->save();

            $sellers[$shop['business_name']] = $seller;
        }

        return $sellers;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Seller>  $sellers
     */
    private function seedProduct(array $row, array $sellers): void
    {
        $product = Product::updateOrCreate(
            ['slug' => Str::slug($row['name'])],
            [
                'seller_id' => $sellers[$row['shop']]->id,
                'category_id' => $this->categoryId($row['category']),
                'name' => $row['name'],
                'description' => $row['description'],
                'price' => $row['price'],
                'stock_quantity' => $row['stock'],
                'low_stock_threshold' => 5,
                'status' => $row['status'] ?? 'active',
            ],
        );

        $this->seedPhotos($product, (int) $row['photos']);
    }

    /**
     * Replace this product's placeholder photos with a fresh set.
     *
     * Only rows this seeder owns are removed — those whose path is a Picsum URL —
     * so re-running it against a product that has since been given real photos
     * through the seller's own upload flow leaves those photos alone.
     */
    private function seedPhotos(Product $product, int $count): void
    {
        $product->images()->where('path', 'like', 'https://picsum.photos/%')->delete();

        for ($index = 0; $index < $count; $index++) {
            ProductImage::create([
                'product_id' => $product->id,
                // Seeded from the slug, so the same product shows the same
                // placeholder on every run instead of a new random photo.
                'path' => 'https://picsum.photos/seed/zef-'.$product->slug.'-'.$index.'/1000/1000',
                'is_primary' => $index === 0,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * The id of a curated category label, or null if the label is not one of them.
     *
     * Returning null rather than throwing is deliberate: `products.category_id` is
     * nullable and the resource renders a null category as "Uncategorised", so a
     * typo here shows up as a visibly miscategorised demo product — which the
     * accompanying test fails on — instead of a crash that hides the cause.
     */
    private function categoryId(string $label): ?int
    {
        return Category::query()->where('label', $label)->value('id');
    }
}
