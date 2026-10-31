<?php

namespace Tests\Feature\Seller;

use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreCategory;
use App\Models\Shared\Category;
use App\Services\Seller\ProductPublishingService;
use App\Services\Seller\StoreCategoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ValidProductPublication;
use Tests\TestCase;

/** A product's main category comes from its store; sellers only add sub-categories. */
class ProductMainCategoryTest extends TestCase
{
    use LazilyRefreshDatabase;
    use ValidProductPublication;

    private Category $apparel;

    private Category $other;

    private Seller $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('public');

        $this->apparel = Category::query()->where('label', "Men's Apparel")->first()
            ?? Category::query()->create(['icon' => 'shirt', 'label' => "Men's Apparel"]);
        $this->other = Category::query()->where('id', '!=', $this->apparel->id)->first()
            ?? Category::query()->create(['icon' => 'gem', 'label' => 'Jewelry']);
        $this->seller = Seller::factory()->create(['status' => 'approved', 'line_of_business_id' => $this->apparel->id]);
        $this->actingAs($this->seller, 'seller');
    }

    private function publish(array $changes = []): TestResponse
    {
        return $this->post(route('seller.products.store'), $changes + $this->publicationDefaults() + [
            'creation_key' => (string) Str::uuid(),
            'name' => 'Oversized cotton shirt',
            'description' => 'A soft cotton shirt with a relaxed oversized fit.',
            'status' => 'active',
            'price' => 299,
            'stock_quantity' => 10,
            'weight' => 600,
            'weight_unit' => 'g',
        ]);
    }

    private function storeCategoryCount(): int
    {
        return StoreCategory::query()->where('seller_id', $this->seller->id)->count();
    }

    private function product(): Product
    {
        return Product::query()->where('seller_id', $this->seller->id)->latest('id')->firstOrFail();
    }

    public function test_a_new_product_gets_the_stores_main_category(): void
    {
        $this->publish()->assertSessionHasNoErrors();

        $this->assertSame($this->apparel->id, $this->product()->category_id);
    }

    public function test_a_submitted_category_is_ignored_on_create(): void
    {
        $this->publish(['category_id' => $this->other->id])->assertSessionHasNoErrors();

        $this->assertSame($this->apparel->id, $this->product()->category_id);
    }

    public function test_a_product_keeps_its_category_when_edited(): void
    {
        $this->publish()->assertSessionHasNoErrors();
        $product = $this->product();
        $product->forceFill(['category_id' => $this->other->id])->save();

        $this->put(route('seller.products.update', $product), [
            'inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants')),
            'category_id' => $this->apparel->id,
            'name' => 'Renamed cotton shirt',
            'description' => 'A soft cotton shirt with a relaxed oversized fit.',
            'dimensions' => ['length' => 35, 'width' => 25, 'height' => 3],
            'price' => 299,
            'stock_quantity' => 10,
            'weight' => 600,
            'weight_unit' => 'g',
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed cotton shirt', $product->fresh()->name);
        $this->assertSame($this->other->id, $product->fresh()->category_id);
    }

    public function test_a_store_with_no_main_category_on_file_still_chooses_one(): void
    {
        $this->seller->forceFill(['line_of_business_id' => null])->save();

        $this->get(route('seller.products.index'))->assertOk()->assertSee('name="category_id"', false);

        $this->publish(['category_id' => $this->other->id])->assertSessionHasNoErrors();
        $this->assertSame($this->other->id, $this->product()->category_id);
    }

    public function test_the_form_shows_the_main_category_locked_with_no_way_to_change_it(): void
    {
        $this->get(route('seller.products.index'))->assertOk()
            ->assertSee('Main category')
            ->assertSee('apparel', false)
            ->assertSee("It can't be changed", false)
            ->assertDontSee('name="category_id"', false)
            ->assertSee('name="new_store_category"', false);
    }

    public function test_a_typed_sub_category_is_created_and_attached(): void
    {
        $this->publish(['new_store_category' => 'Shoes'])->assertSessionHasNoErrors();

        $this->assertSame(['Shoes'], $this->product()->storeCategories->pluck('name')->all());
        $this->assertSame($this->apparel->id, $this->product()->category_id);
    }

    public function test_a_typed_name_the_store_already_has_is_reused(): void
    {
        $existing = app(StoreCategoryService::class)->save($this->seller, ['name' => 'Shoes']);

        $this->publish(['new_store_category' => ' shoes '])->assertSessionHasNoErrors();

        $this->assertSame([$existing->id], $this->product()->storeCategories->modelKeys());
        $this->assertSame(1, $this->storeCategoryCount());
    }

    public function test_ticking_and_typing_the_same_one_does_not_double_it(): void
    {
        $existing = app(StoreCategoryService::class)->save($this->seller, ['name' => 'Shoes']);

        $this->publish(['store_categories_present' => '1', 'store_category_ids' => [$existing->id], 'new_store_category' => 'Shoes'])
            ->assertSessionHasNoErrors();

        $this->assertSame([$existing->id], $this->product()->storeCategories->modelKeys());
    }

    public function test_a_fourth_sub_category_is_refused_and_creates_nothing(): void
    {
        $service = app(StoreCategoryService::class);
        $ids = array_map(fn (string $name) => $service->save($this->seller, ['name' => $name])->id, ['Shirts', 'Pants', 'Shoes']);

        $this->publish(['store_categories_present' => '1', 'store_category_ids' => $ids, 'new_store_category' => 'Hats'])
            ->assertSessionHasErrors('store_category_ids');

        $this->assertSame(0, Product::query()->where('seller_id', $this->seller->id)->count());
        $this->assertSame(3, $this->storeCategoryCount());
    }

    public function test_a_long_sub_category_name_is_refused(): void
    {
        $this->publish(['new_store_category' => str_repeat('a', 51)])->assertSessionHasErrors('new_store_category');

        $this->assertSame(0, Product::query()->where('seller_id', $this->seller->id)->count());
    }

    private function listed(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + [
            'seller_id' => $this->seller->id,
            'category_id' => $this->apparel->id,
            'status' => 'active',
            'stock_quantity' => 5,
            'brand' => 'Acme',
            'condition' => 'New',
            'description' => 'A soft cotton shirt with a relaxed oversized fit.',
        ]);
    }

    public function test_buyer_page_shows_specifications_with_category_and_sub_category(): void
    {
        $product = $this->listed();
        $shoes = app(StoreCategoryService::class)->save($this->seller, ['name' => 'Shoes']);
        $product->storeCategories()->attach($shoes);

        $this->get(route('buyer.products.show', $product))->assertOk()
            ->assertSeeInOrder(['Specifications', 'Category', 'Men&#039;s Apparel', 'Sub-category', 'Shoes', 'Store', $this->seller->business_name, 'Brand', 'Acme', 'Condition', 'New', 'Description'], false)
            ->assertSee(route('buyer.categories.show', $this->apparel->id), false)
            ->assertSee(route('buyer.stores.categories.show', ['seller' => $this->seller, 'storeCategory' => $shoes]), false);
    }

    public function test_a_hidden_sub_category_stays_off_the_buyer_page(): void
    {
        $product = $this->listed();
        $hidden = app(StoreCategoryService::class)->save($this->seller, ['name' => 'Secret Line']);
        $hidden->forceFill(['is_visible' => false])->save();
        $product->storeCategories()->attach($hidden);

        $this->get(route('buyer.products.show', $product))->assertOk()
            ->assertDontSee('Secret Line')
            ->assertDontSee('Sub-category');
    }

    public function test_no_brand_is_not_listed_as_a_specification(): void
    {
        $this->get(route('buyer.products.show', $this->listed(['brand' => 'No brand'])))->assertOk()->assertDontSee('>Brand</dt>', false);
    }

    public function test_seller_strip_links_to_the_store_and_offers_a_message_button_once(): void
    {
        $product = $this->listed();
        auth('seller')->logout();

        $guest = $this->get(route('buyer.products.show', $product))->assertOk()
            ->assertSee('Sold by')
            ->assertSee('Visit store')
            ->assertSee('Log in to message seller');
        $this->assertSame(1, substr_count($guest->getContent(), 'id="pdp-chat-seller"'));

        $this->actingAs(Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]), 'buyer');

        $this->get(route('buyer.products.show', $product))->assertOk()
            ->assertSee('Message seller')
            ->assertDontSee('Log in to message seller');
    }

    public function test_empty_review_summary_reads_plainly(): void
    {
        $this->get(route('buyer.products.show', $this->listed()))->assertOk()
            ->assertSee('0.0')
            ->assertSee('No ratings yet')
            ->assertSee('Rate it from your orders')
            ->assertSee('No reviews yet. Ratings from buyers will appear here.');
    }
}
