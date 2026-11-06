<?php

namespace Tests\Feature\Seller;

use App\Models\Buyer\Buyer;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreCategory;
use App\Models\Shared\OrderItem;
use App\Services\Seller\ProductPublishingService;
use App\Services\Seller\StockService;
use App\Services\Seller\StoreCategoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreCategoryLimitsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function category(Seller $seller, string $name, ?StoreCategory $parent = null): StoreCategory
    {
        $category = (new StoreCategory)->forceFill(['seller_id' => $seller->id, 'name' => $name, 'normalized_name' => mb_strtolower($name), 'parent_id' => $parent?->id]);
        $category->save();

        return $category;
    }

    public function test_sel_scat_011_allows_level_two_and_rejects_level_three(): void
    {
        $seller = Seller::factory()->create();
        $root = $this->category($seller, 'Home Decor');
        $child = $this->category($seller, 'Lamps', $root);
        $this->actingAs($seller, 'seller');

        $this->post(route('seller.store-categories.store'), ['name' => 'Wall Decor', 'parent_id' => $root->id])->assertSessionHasNoErrors();
        $this->post(route('seller.store-categories.store'), ['name' => 'Table Lamps', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');

        $this->assertDatabaseHas('store_categories', ['seller_id' => $seller->id, 'name' => 'Wall Decor', 'parent_id' => $root->id]);
        $this->assertDatabaseMissing('store_categories', ['name' => 'Table Lamps']);
        $this->assertDatabaseCount('store_categories', 3);
        $this->get(route('seller.store-categories.index'))->assertOk()->assertSee('Home Decor')->assertSee('Wall Decor');
    }

    public function test_reparenting_cannot_create_a_third_level_or_a_cycle(): void
    {
        $seller = Seller::factory()->create();
        $root = $this->category($seller, 'Home Decor');
        $child = $this->category($seller, 'Lamps', $root);
        $other = $this->category($seller, 'Gift Ideas');
        $this->actingAs($seller, 'seller');

        foreach ([$root->id, $child->id, $other->id] as $parentId) {
            $this->patch(route('seller.store-categories.update', $root), ['name' => 'Home Decor', 'parent_id' => $parentId])->assertSessionHasErrors('parent_id');
        }

        $this->assertNull($root->fresh()->parent_id);
        $this->assertSame($root->id, $child->fresh()->parent_id);
    }

    public function test_category_edits_keep_valid_two_level_relationships(): void
    {
        $seller = Seller::factory()->create();
        $root = $this->category($seller, 'Home Decor');
        $child = $this->category($seller, 'Lamps', $root);
        $other = $this->category($seller, 'Gift Ideas');

        $this->actingAs($seller, 'seller')->patch(route('seller.store-categories.update', $child), ['name' => 'Desk Lamps', 'parent_id' => $other->id])->assertSessionHasNoErrors();

        $this->assertSame($other->id, $child->fresh()->parent_id);
        $this->assertSame('Desk Lamps', $child->fresh()->name);
    }

    public function test_sel_scat_012_preserves_three_memberships_when_a_fourth_is_rejected(): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'name' => 'Capiz Shell Lamp', 'stock_quantity' => 9]);
        $variant = $product->variants()->sole();
        app(StockService::class)->set($variant, 11, StockService::REASON_SELLER_ADJUSTMENT);
        $item = OrderItem::factory()->create(['product_id' => $product->id, 'variant_id' => $variant->id, 'product_name' => 'Original lamp name', 'price_minor' => 12500]);
        $beforeProduct = $product->fresh()->getAttributes();
        $beforeVariants = $product->variants()->get()->toArray();
        $beforeItem = $item->fresh()->getAttributes();
        $beforeLedger = InventoryTransaction::query()->get()->toArray();
        $categories = collect(['Lamps', 'Best Sellers', 'Gift Ideas', 'New Arrivals'])->map(fn ($name) => $this->category($seller, $name));
        $three = $categories->take(3)->pluck('id')->all();
        $this->actingAs($seller, 'seller');

        $this->patch(route('seller.store-categories.assign', $product), ['store_category_ids' => $three])->assertSessionHasNoErrors();
        $this->patch(route('seller.store-categories.assign', $product), ['store_category_ids' => $categories->pluck('id')->all()])->assertSessionHasErrors('store_category_ids');
        $this->patch(route('seller.store-categories.assign', $product), ['store_category_ids' => $three])->assertSessionHasNoErrors();

        $this->assertSame($three, $product->storeCategories()->orderBy('store_categories.id')->pluck('store_categories.id')->all());
        $this->assertDatabaseCount('product_store_category', 3);
        $this->assertSame($beforeProduct, $product->fresh()->getAttributes());
        $this->assertSame($beforeVariants, $product->variants()->get()->toArray());
        $this->assertSame($beforeItem, $item->fresh()->getAttributes());
        $this->assertSame($beforeLedger, InventoryTransaction::query()->get()->toArray());
        $this->get(route('seller.store-categories.index', ['product' => $product->id]))->assertOk()
            ->assertSee(route('seller.store-categories.assign', $product), false)
            ->assertViewHas('selectedProduct', fn ($selected) => $selected->storeCategories->count() === 3);
        $this->actingAs(Buyer::factory()->create(), 'buyer');
        foreach ($categories->take(3) as $category) {
            $this->get(route('buyer.stores.categories.show', [$seller, $category]))->assertOk()->assertSee('Capiz Shell Lamp')
                ->assertViewHas('products', fn ($products) => $products->total() === 1 && $products->first()->id === $product->id);
        }
        $this->get(route('buyer.stores.categories.show', [$seller, $categories->last()]))->assertViewHas('products', fn ($products) => $products->total() === 0);
    }

    public static function validMembershipCounts(): array
    {
        return [[0], [1], [2], [3]];
    }

    #[DataProvider('validMembershipCounts')]
    public function test_products_can_have_zero_to_three_store_categories(int $count): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->category($seller, 'Category '.$i)->id;
        }

        $this->actingAs($seller, 'seller')->patch(route('seller.store-categories.assign', $product), ['store_category_ids' => $ids])->assertSessionHasNoErrors();

        $this->assertSame($count, $product->storeCategories()->count());
    }

    public function test_another_sellers_category_cannot_be_used_as_a_parent_or_membership(): void
    {
        $seller = Seller::factory()->create();
        $other = Seller::factory()->create();
        $foreign = $this->category($other, 'Private category');
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $this->actingAs($seller, 'seller');

        $this->post(route('seller.store-categories.store'), ['name' => 'Forbidden child', 'parent_id' => $foreign->id])->assertSessionHasErrors('parent_id');
        $this->patch(route('seller.store-categories.assign', $product), ['store_category_ids' => [$foreign->id]])->assertSessionHasErrors('store_category_ids');
        $this->patch(route('seller.store-categories.update', $foreign), ['name' => 'Hijacked'])->assertNotFound();

        $this->assertDatabaseCount('store_categories', 1);
        $this->assertDatabaseCount('product_store_category', 0);
        $this->assertSame('Private category', $foreign->fresh()->name);
        $this->get(route('seller.store-categories.index'))->assertDontSee('Private category');
    }

    public function test_another_sellers_product_cannot_be_categorized_or_opened_in_the_editor(): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($seller, 'seller')->patch(route('seller.store-categories.assign', $product), ['store_category_ids' => []])->assertNotFound();
        $this->get(route('seller.store-categories.index', ['product' => $product->id]))->assertNotFound();

        $this->assertDatabaseCount('product_store_category', 0);
    }

    public function test_guest_and_buyer_cannot_manage_store_categories(): void
    {
        $this->get(route('seller.store-categories.index'))->assertRedirect(route('home', ['login' => 1]));
        $this->post(route('seller.store-categories.store'), ['name' => 'Unauthorized'])->assertRedirect(route('home', ['login' => 1]));
        $this->actingAs(Buyer::factory()->create(), 'buyer')->post(route('seller.store-categories.store'), ['name' => 'Unauthorized'])->assertRedirect(route('home', ['login' => 1]));

        $this->assertDatabaseCount('store_categories', 0);
    }

    public function test_duplicate_memberships_are_rejected_and_can_be_cleared_explicitly(): void
    {
        $seller = Seller::factory()->create();
        $category = $this->category($seller, 'Lamps');
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $product->storeCategories()->attach($category);
        $this->actingAs($seller, 'seller');

        $this->patch(route('seller.store-categories.assign', $product), ['store_category_ids' => [$category->id, $category->id]])->assertSessionHasErrors('store_category_ids.0');
        $this->assertSame(1, $product->storeCategories()->count());
        $this->patch(route('seller.store-categories.assign', $product), ['store_category_ids' => []])->assertSessionHasNoErrors();

        $this->assertSame(0, $product->storeCategories()->count());
    }

    public function test_platform_category_and_store_memberships_survive_existing_product_updates(): void
    {
        $seller = Seller::factory()->create();
        $category = $this->category($seller, 'Lamps');
        $product = Product::factory()->create(['seller_id' => $seller->id, 'category_id' => $seller->line_of_business_id, 'stock_quantity' => 5]);
        $product->storeCategories()->attach($category);
        $platformCategory = $product->category_id;

        $this->actingAs($seller, 'seller')->put(route('seller.products.update', $product), [
            'inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants')),
            'name' => $product->name, 'price' => $product->price, 'weight' => 250, 'weight_unit' => 'g', 'stock_quantity' => 5, 'status' => 'draft',
        ])->assertSessionHasNoErrors();

        $this->assertSame($platformCategory, $product->fresh()->category_id);
        $this->assertSame([$category->id], $product->storeCategories()->pluck('store_categories.id')->all());
    }

    public function test_public_category_routes_cannot_mix_stores_or_expose_unlisted_products(): void
    {
        $seller = Seller::factory()->create();
        $category = $this->category($seller, 'Lamps');
        $other = Seller::factory()->create();
        $this->get(route('buyer.stores.categories.show', [$other, $category]))->assertNotFound();
        foreach (['draft', 'inactive'] as $status) {
            $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => $status]);
            $product->storeCategories()->attach($category);
        }

        $this->get(route('buyer.stores.categories.show', [$seller, $category]))->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 0);
        $seller->forceFill(['status' => 'suspended'])->save();
        $this->get(route('buyer.stores.show', $seller))->assertNotFound();
    }

    public function test_direct_service_calls_cannot_bypass_depth_or_membership_limits(): void
    {
        $seller = Seller::factory()->create();
        $root = $this->category($seller, 'Root');
        $child = $this->category($seller, 'Child', $root);
        $service = app(StoreCategoryService::class);
        try {
            $service->save($seller, ['name' => 'Third level', 'parent_id' => $child->id]);
            $this->fail('Depth limit must be enforced.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('parent_id', $exception->errors());
        }
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        try {
            $service->assign($seller, $product, [1, 2, 3, 4]);
            $this->fail('Membership limit must be enforced.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('store_category_ids', $exception->errors());
        }

        $this->assertDatabaseCount('store_categories', 2);
        $this->assertDatabaseCount('product_store_category', 0);
    }

    public function test_category_name_validation_does_not_leak_between_stores(): void
    {
        $seller = Seller::factory()->create();
        $other = Seller::factory()->create();
        $this->category($seller, 'Lamps');

        $this->actingAs($seller, 'seller')->post(route('seller.store-categories.store'), ['name' => ' lamps '])->assertSessionHasErrors('name');
        $this->post(route('seller.store-categories.store'), ['name' => '   '])->assertSessionHasErrors('name');
        $this->actingAs($other, 'seller')->post(route('seller.store-categories.store'), ['name' => 'Lamps'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('store_categories', 2);
        $this->assertDatabaseHas('store_categories', ['seller_id' => $other->id, 'name' => 'Lamps']);
    }
}
