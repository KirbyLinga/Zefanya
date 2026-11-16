<?php

namespace Tests\Feature\Seller;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreCategory;
use App\Models\Shared\Category;
use App\Models\Shared\OrderItem;
use App\Services\Seller\StockService;
use App\Services\Seller\StoreCategoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreCategoryManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function category(Seller $seller, string $name, ?StoreCategory $parent = null): StoreCategory
    {
        return app(StoreCategoryService::class)->save($seller, ['name' => $name, 'parent_id' => $parent?->id]);
    }

    private function product(Seller $seller, array $categories, string $name = 'Abaca Tote'): Product
    {
        $product = Product::factory()->create(['seller_id' => $seller->id, 'name' => $name, 'stock_quantity' => 8]);
        app(StoreCategoryService::class)->assign($seller, $product, array_map(fn ($category) => $category->id, $categories));

        return $product;
    }

    private function confirmDeletion(Seller $seller, StoreCategory $category): array
    {
        $response = $this->actingAs($seller, 'seller')->get(route('seller.store-categories.delete-confirmation', $category))->assertOk();

        return ['confirmed' => '1', 'revision' => $response->viewData('revision')];
    }

    public function test_sel_scat_001_assigns_and_filters_one_category_without_other_products(): void
    {
        $seller = Seller::factory()->create();
        $category = $this->category($seller, 'Pasalubong Picks');
        $product = $this->product($seller, [$category]);
        $this->product($seller, [], 'Other product');
        $this->actingAs($seller, 'seller')->get(route('seller.store-categories.index'))->assertSee('Pasalubong Picks')->assertSee('(1 products)');
        $this->get(route('buyer.stores.categories.show', [$seller, $category]))->assertViewHas('products', fn ($products) => $products->modelKeys() === [$product->id]);
    }

    public static function categoryNames(): array
    {
        return [['', false], ['   ', false], ['B', true], [str_repeat('a', 50), true], [str_repeat('a', 51), false]];
    }

    #[DataProvider('categoryNames')]
    public function test_sel_scat_002_category_name_boundaries(string $name, bool $valid): void
    {
        $seller = Seller::factory()->create();
        $response = $this->actingAs($seller, 'seller')->post(route('seller.store-categories.store'), ['name' => $name]);
        if ($valid) {
            $response->assertSessionHasNoErrors();
            $this->assertSame($name, StoreCategory::query()->sole()->name);
        } else {
            $response->assertSessionHasErrors('name');
            $this->assertDatabaseCount('store_categories', 0);
        }
    }

    public static function duplicateNames(): array
    {
        return [['Bags'], ['BAGS'], ['  Bags  ']];
    }

    #[DataProvider('duplicateNames')]
    public function test_sel_scat_003_duplicate_names_are_case_and_space_insensitive(string $name): void
    {
        $seller = Seller::factory()->create();
        $this->category($seller, 'Bags');
        $this->actingAs($seller, 'seller')->post(route('seller.store-categories.store'), ['name' => $name])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('store_categories', 1);
    }

    public function test_sel_scat_004_and_005_names_are_store_scoped_and_renames_cannot_collide(): void
    {
        $one = Seller::factory()->create();
        $two = Seller::factory()->create();
        $bags = $this->category($one, 'Bags');
        $kitchen = $this->category($one, 'Kitchen & Dining');
        $this->actingAs($two, 'seller')->post(route('seller.store-categories.store'), ['name' => 'Bags'])->assertSessionHasNoErrors();
        $this->actingAs($one, 'seller')->patch(route('seller.store-categories.update', $kitchen), ['name' => 'bags'])->assertSessionHasErrors('name');
        $this->assertSame('Kitchen & Dining', $kitchen->fresh()->name);
        $this->assertSame('Bags', $bags->fresh()->name);
        $this->assertDatabaseCount('store_categories', 3);
    }

    public function test_sel_scat_006_rename_and_reorder_persist_in_both_interfaces_without_membership_changes(): void
    {
        $seller = Seller::factory()->create();
        $bags = $this->category($seller, 'Bags');
        $home = $this->category($seller, 'Home Decor');
        $kitchen = $this->category($seller, 'Kitchen & Dining');
        $product = $this->product($seller, [$kitchen]);
        $before = $product->fresh()->getRawOriginal();
        $this->actingAs($seller, 'seller')->patch(route('seller.store-categories.update', $kitchen), ['name' => 'Kitchen'])->assertSessionHasNoErrors();
        $order = [$kitchen->id, $bags->id, $home->id];
        $this->post(route('seller.store-categories.reorder'), ['category_ids' => $order])->assertSessionHasNoErrors();
        $this->get(route('seller.store-categories.index'))->assertViewHas('categories', fn ($rows) => $rows->modelKeys() === $order);
        $this->get(route('buyer.stores.show', $seller))->assertViewHas('storeCategories', fn ($rows) => $rows->modelKeys() === $order)->assertSee('Kitchen');
        $this->assertSame([$kitchen->id], $product->storeCategories()->pluck('store_categories.id')->all());
        $this->assertSame($before, $product->fresh()->getRawOriginal());
    }

    public function test_move_buttons_reorder_siblings_and_keep_children_under_their_parent(): void
    {
        $seller = Seller::factory()->create();
        $root = $this->category($seller, 'Home Decor');
        $first = $this->category($seller, 'Lamps', $root);
        $second = $this->category($seller, 'Baskets', $root);
        $other = $this->category($seller, 'Gift Ideas');
        $this->actingAs($seller, 'seller')->post(route('seller.store-categories.move', $second), ['direction' => 'up'])->assertSessionHasNoErrors();
        $this->get(route('buyer.stores.show', $seller))->assertViewHas('storeCategories', fn ($roots) => $roots->first()->children->modelKeys() === [$second->id, $first->id]);
        $this->get(route('seller.store-categories.index'))->assertViewHas('categories', fn ($rows) => $rows->modelKeys() === [$root->id, $second->id, $first->id, $other->id]);
        $this->assertSame($root->id, $second->fresh()->parent_id);
    }

    public function test_sel_scat_007_and_008_hiding_only_affects_navigation_and_show_restores_position(): void
    {
        $seller = Seller::factory()->create();
        $bags = $this->category($seller, 'Bags');
        $other = $this->category($seller, 'Other');
        $product = $this->product($seller, [$bags]);
        $before = $product->fresh()->getRawOriginal();
        $position = $bags->sort_order;
        $this->actingAs($seller, 'seller')->patch(route('seller.store-categories.visibility', $bags), ['is_visible' => '0'])->assertSessionHasNoErrors();
        $this->get(route('buyer.stores.show', $seller))->assertViewHas('storeCategories', fn ($rows) => $rows->modelKeys() === [$other->id])->assertViewHas('products', fn ($rows) => $rows->contains('id', $product->id));
        $this->get(route('buyer.stores.categories.show', [$seller, $bags]))->assertNotFound();
        $this->get(route('buyer.search.index', ['q' => $product->name]))->assertViewHas('products', fn ($rows) => $rows->contains('id', $product->id));
        $this->get(route('buyer.products.show', $product))->assertOk();
        $this->get(route('buyer.stores.uncategorized', $seller))->assertViewHas('products', fn ($rows) => $rows->isEmpty());
        $this->assertSame($before, $product->fresh()->getRawOriginal());
        $this->assertSame([$bags->id], $product->storeCategories()->pluck('store_categories.id')->all());
        $this->patch(route('seller.store-categories.visibility', $bags), ['is_visible' => '1'])->assertSessionHasNoErrors();
        $this->assertSame($position, $bags->fresh()->sort_order);
        $this->get(route('buyer.stores.show', $seller))->assertViewHas('storeCategories', fn ($rows) => $rows->modelKeys() === [$bags->id, $other->id]);
    }

    public function test_hidden_parents_hide_child_navigation_and_urls_without_changing_child_flags(): void
    {
        $seller = Seller::factory()->create();
        $root = $this->category($seller, 'Home Decor');
        $child = $this->category($seller, 'Lamps', $root);
        $this->product($seller, [$child]);
        $this->actingAs($seller, 'seller')->patch(route('seller.store-categories.visibility', $root), ['is_visible' => false])->assertSessionHasNoErrors();
        $this->get(route('buyer.stores.show', $seller))->assertViewHas('storeCategories', fn ($rows) => $rows->isEmpty());
        $this->get(route('buyer.stores.categories.show', [$seller, $child]))->assertNotFound();
        $this->assertTrue($child->fresh()->is_visible);
        $this->patch(route('seller.store-categories.visibility', $root), ['is_visible' => true])->assertSessionHasNoErrors();
        $this->get(route('buyer.stores.categories.show', [$seller, $child]))->assertOk();
        $this->patch(route('seller.store-categories.visibility', $child), ['is_visible' => false])->assertSessionHasNoErrors();
        $this->get(route('buyer.stores.show', $seller))->assertViewHas('storeCategories', fn ($rows) => $rows->count() === 1 && $rows->first()->children->isEmpty());
    }

    public function test_sel_scat_009_preview_cancel_and_confirm_preserve_products_and_their_history(): void
    {
        $seller = Seller::factory()->create();
        $category = $this->category($seller, 'Baskets');
        $product = $this->product($seller, [$category], 'Rattan Fruit Basket');
        $variant = $product->variants()->sole();
        app(StockService::class)->set($variant, 9, StockService::REASON_SELLER_ADJUSTMENT);
        $item = OrderItem::factory()->create(['product_id' => $product->id, 'variant_id' => $variant->id]);
        $before = $product->fresh()->getRawOriginal();
        $itemBefore = $item->fresh()->getRawOriginal();
        $ledger = InventoryTransaction::query()->get()->toArray();
        $preview = $this->actingAs($seller, 'seller')->get(route('seller.store-categories.delete-confirmation', $category))
            ->assertSee('1 product will move to Uncategorized')->assertSee('Cancel');
        $this->get(route('seller.store-categories.index'))->assertSee('Baskets');
        $this->assertModelExists($category);
        $this->assertSame(1, $product->storeCategories()->count());
        $this->delete(route('seller.store-categories.destroy', $category), ['confirmed' => '1', 'revision' => $preview->viewData('revision')])->assertSessionHasNoErrors();
        $this->assertModelMissing($category);
        $this->assertSame(0, $product->storeCategories()->count());
        $this->assertSame($before, $product->fresh()->getRawOriginal());
        $this->assertSame($itemBefore, $item->fresh()->getRawOriginal());
        $this->assertSame($ledger, InventoryTransaction::query()->get()->toArray());
        $this->assertSame(9, $variant->fresh()->stock_quantity);
        $this->get(route('buyer.stores.uncategorized', $seller))->assertViewHas('products', fn ($rows) => $rows->modelKeys() === [$product->id]);
        $this->get(route('buyer.products.show', $product))->assertOk();
        $this->get(route('seller.store-categories.index', ['product' => $product->id]))->assertSee('is in Uncategorized');
    }

    public function test_sel_scat_010_deletes_the_parent_and_children_and_counts_distinct_affected_products(): void
    {
        $seller = Seller::factory()->create();
        $root = $this->category($seller, 'Home Decor');
        $lamps = $this->category($seller, 'Lamps', $root);
        $baskets = $this->category($seller, 'Baskets', $root);
        $one = $this->product($seller, [$lamps, $root], 'Capiz Shell Lamp');
        $two = $this->product($seller, [$baskets], 'Rattan Basket');
        $preview = $this->actingAs($seller, 'seller')->get(route('seller.store-categories.delete-confirmation', $root))
            ->assertSee('Lamps')->assertSee('Baskets')->assertSee('2 products affected')->assertSee('2 products will move to Uncategorized');
        $this->delete(route('seller.store-categories.destroy', $root), ['confirmed' => true, 'revision' => $preview->viewData('revision')])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('store_categories', 0);
        $this->assertDatabaseCount('product_store_category', 0);
        $this->assertSame('active', $one->fresh()->status);
        $this->assertSame('active', $two->fresh()->status);
        $this->get(route('buyer.stores.uncategorized', $seller))->assertViewHas('products', fn ($rows) => $rows->count() === 2);
    }

    public function test_deletion_keeps_memberships_outside_the_subtree_without_creating_a_fourth_membership(): void
    {
        $seller = Seller::factory()->create();
        $root = $this->category($seller, 'Home Decor');
        $child = $this->category($seller, 'Lamps', $root);
        $other = $this->category($seller, 'Gift Ideas');
        $product = $this->product($seller, [$root, $child, $other]);
        $data = $this->confirmDeletion($seller, $root);
        $this->delete(route('seller.store-categories.destroy', $root), $data)->assertSessionHasNoErrors();
        $this->assertSame([$other->id], $product->storeCategories()->pluck('store_categories.id')->all());
        $this->get(route('buyer.stores.uncategorized', $seller))->assertViewHas('products', fn ($rows) => $rows->isEmpty());
        $this->get(route('buyer.stores.categories.show', [$seller, $other]))->assertViewHas('products', fn ($rows) => $rows->modelKeys() === [$product->id]);
    }

    public function test_deletion_requires_confirmation_and_rejects_a_stale_warning(): void
    {
        $seller = Seller::factory()->create();
        $category = $this->category($seller, 'Bags');
        $data = $this->confirmDeletion($seller, $category);
        $this->delete(route('seller.store-categories.destroy', $category))->assertSessionHasErrors(['confirmed', 'revision']);
        $product = $this->product($seller, [$category]);
        $this->delete(route('seller.store-categories.destroy', $category), $data)->assertSessionHasErrors('category');
        $this->assertModelExists($category);
        $this->assertSame(1, $product->storeCategories()->count());
    }

    public function test_sel_scat_011_012_existing_depth_and_membership_limits_remain_enforced(): void
    {
        $seller = Seller::factory()->create();
        $root = $this->category($seller, 'Home');
        $child = $this->category($seller, 'Lamps', $root);
        $other = $this->category($seller, 'Gifts');
        $fourth = $this->category($seller, 'New arrivals');
        $product = $this->product($seller, [$root, $child, $other]);
        $this->actingAs($seller, 'seller')->post(route('seller.store-categories.store'), ['name' => 'Table Lamps', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');
        $this->patch(route('seller.store-categories.assign', $product), ['store_category_ids' => [$root->id, $child->id, $other->id, $fourth->id]])->assertSessionHasErrors('store_category_ids');
        $this->assertSame(3, $product->storeCategories()->count());
    }

    public function test_sel_scat_013_store_category_changes_never_change_the_platform_category(): void
    {
        $seller = Seller::factory()->create();
        $platform = Category::create(['label' => "Women's Bags", 'icon' => 'bag']);
        $category = $this->category($seller, 'Kitchen & Dining');
        $product = Product::factory()->create(['seller_id' => $seller->id, 'category_id' => $platform->id]);
        $this->actingAs($seller, 'seller')->patch(route('seller.store-categories.assign', $product), ['store_category_ids' => [$category->id]])->assertSessionHasNoErrors();
        $this->assertSame($platform->id, $product->fresh()->category_id);
        $this->get(route('buyer.stores.categories.show', [$seller, $category]))->assertViewHas('products', fn ($rows) => $rows->contains('id', $product->id));
        $this->get(route('buyer.categories.show', $platform))->assertSee($product->name);
    }

    public function test_reorder_rejects_incomplete_duplicate_and_cross_store_selections_without_changes(): void
    {
        $seller = Seller::factory()->create();
        $one = $this->category($seller, 'Bags');
        $two = $this->category($seller, 'Home');
        $foreign = $this->category(Seller::factory()->create(), 'Foreign');
        $this->actingAs($seller, 'seller');
        foreach ([[$one->id], [$one->id, $one->id], [$one->id, $foreign->id]] as $ids) {
            $this->post(route('seller.store-categories.reorder'), ['category_ids' => $ids])->assertSessionHasErrors();
            $this->assertSame([$one->id, $two->id], StoreCategory::query()->where('seller_id', $seller->id)->displayOrder()->pluck('id')->all());
        }
    }

    public function test_foreign_categories_cannot_be_renamed_moved_hidden_or_deleted(): void
    {
        $owner = Seller::factory()->create();
        $category = $this->category($owner, 'Private category');
        $seller = Seller::factory()->create();
        $this->actingAs($seller, 'seller')->patch(route('seller.store-categories.update', $category), ['name' => 'Changed'])->assertNotFound();
        $this->patch(route('seller.store-categories.visibility', $category), ['is_visible' => false])->assertNotFound();
        $this->post(route('seller.store-categories.move', $category), ['direction' => 'up'])->assertNotFound();
        $this->get(route('seller.store-categories.delete-confirmation', $category))->assertNotFound();
        $this->delete(route('seller.store-categories.destroy', $category), ['confirmed' => true, 'revision' => str_repeat('a', 64)])->assertNotFound();
        $this->assertSame('Private category', $category->fresh()->name);
        $this->assertTrue($category->fresh()->is_visible);
    }

    public static function roles(): array
    {
        return [['buyer', Buyer::class], ['admin', Admin::class], ['logistics', LogisticsProvider::class], ['rider', Rider::class]];
    }

    #[DataProvider('roles')]
    public function test_other_roles_cannot_reorder_hide_or_delete_categories(string $guard, string $model): void
    {
        $seller = Seller::factory()->create();
        $category = $this->category($seller, 'Bags');
        $this->actingAs($model::factory()->create(), $guard)->postJson(route('seller.store-categories.reorder'), ['category_ids' => [$category->id]])->assertUnauthorized();
        $this->patchJson(route('seller.store-categories.visibility', $category), ['is_visible' => false])->assertUnauthorized();
        $this->getJson(route('seller.store-categories.delete-confirmation', $category))->assertUnauthorized();
        $this->deleteJson(route('seller.store-categories.destroy', $category), ['confirmed' => true, 'revision' => str_repeat('a', 64)])->assertUnauthorized();
        $this->assertModelExists($category);
    }

    public function test_guests_and_unapproved_sellers_cannot_change_categories(): void
    {
        $seller = Seller::factory()->pending()->create();
        $category = $this->category($seller, 'Bags');
        $this->postJson(route('seller.store-categories.reorder'), ['category_ids' => [$category->id]])->assertUnauthorized();
        $this->actingAs($seller, 'seller')->patch(route('seller.store-categories.visibility', $category), ['is_visible' => false])->assertForbidden()->assertSee('under review');
        $this->assertAuthenticatedAs($seller, 'seller');
        $this->assertTrue($category->fresh()->is_visible);
    }

    public function test_renaming_a_subcategory_preserves_its_parent_position_and_memberships(): void
    {
        $seller = Seller::factory()->create();
        $parent = $this->category($seller, 'Home Decor');
        $child = $this->category($seller, 'Lamps', $parent);
        $product = $this->product($seller, [$child]);
        $position = $child->sort_order;
        $this->actingAs($seller, 'seller')->patch(route('seller.store-categories.update', $child), ['name' => 'Desk Lamps'])->assertSessionHasNoErrors();
        $this->assertSame($parent->id, $child->fresh()->parent_id);
        $this->assertSame('Desk Lamps', $child->fresh()->name);
        $this->assertSame($position, $child->fresh()->sort_order);
        $this->assertTrue($product->storeCategories()->whereKey($child->id)->exists());
        $this->get(route('buyer.stores.show', $seller))->assertViewHas('storeCategories', fn ($rows) => $rows->firstWhere('id', $parent->id)->children->contains('id', $child->id));
    }

    public function test_explicitly_clearing_a_subcategory_parent_promotes_it_to_top_level(): void
    {
        $seller = Seller::factory()->create();
        $parent = $this->category($seller, 'Home Decor');
        $child = $this->category($seller, 'Lamps', $parent);
        $this->actingAs($seller, 'seller')->patch(route('seller.store-categories.update', $child), ['name' => 'Lamps', 'parent_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($child->fresh()->parent_id);
        $this->assertModelExists($parent);
    }

    public function test_existing_equal_positions_keep_the_previous_alphabetical_order(): void
    {
        $seller = Seller::factory()->create();
        foreach (['Zebra', 'Alpha'] as $name) {
            (new StoreCategory)->forceFill(['seller_id' => $seller->id, 'name' => $name, 'normalized_name' => strtolower($name)])->save();
        }
        $this->get(route('buyer.stores.show', $seller))->assertViewHas('storeCategories', fn ($rows) => $rows->pluck('name')->all() === ['Alpha', 'Zebra']);
        $this->actingAs($seller, 'seller')->get(route('seller.store-categories.index'))->assertViewHas('categories', fn ($rows) => $rows->pluck('name')->all() === ['Alpha', 'Zebra']);
    }

    public function test_deleting_a_child_keeps_its_parent_and_sibling_and_soft_deleted_products(): void
    {
        $seller = Seller::factory()->create();
        $root = $this->category($seller, 'Home');
        $child = $this->category($seller, 'Lamps', $root);
        $sibling = $this->category($seller, 'Baskets', $root);
        $product = $this->product($seller, [$child]);
        $product->delete();
        $before = $product->fresh()->getRawOriginal();
        $data = $this->confirmDeletion($seller, $child);
        $this->delete(route('seller.store-categories.destroy', $child), $data)->assertSessionHasNoErrors();
        $this->assertModelExists($root);
        $this->assertModelExists($sibling);
        $this->assertModelMissing($child);
        $this->assertSame($before, Product::withTrashed()->findOrFail($product->id)->getRawOriginal());
        $this->assertSame(0, $product->storeCategories()->count());
    }

    public function test_unexpected_legacy_subtrees_are_rejected_without_automatic_reassignment(): void
    {
        $seller = Seller::factory()->create();
        $root = $this->category($seller, 'Home');
        $child = $this->category($seller, 'Lamps', $root);
        $third = (new StoreCategory)->forceFill(['seller_id' => $seller->id, 'parent_id' => $child->id, 'name' => 'Legacy third level', 'normalized_name' => 'legacy third level']);
        $third->save();
        $product = $this->product($seller, [$third]);
        $this->actingAs($seller, 'seller')->get(route('seller.store-categories.delete-confirmation', $root))->assertSessionHasErrors('category');
        $this->assertDatabaseCount('store_categories', 3);
        $this->assertSame([$third->id], $product->storeCategories()->pluck('store_categories.id')->all());
    }

    public function test_cross_store_legacy_memberships_stop_deletion_without_modifying_either_store(): void
    {
        $seller = Seller::factory()->create();
        $category = $this->category($seller, 'Bags');
        $foreign = Product::factory()->create();
        $category->products()->attach($foreign->id);
        $before = $foreign->fresh()->getRawOriginal();
        $this->actingAs($seller, 'seller')->get(route('seller.store-categories.delete-confirmation', $category))->assertSessionHasErrors('category');
        $this->assertModelExists($category);
        $this->assertSame($before, $foreign->fresh()->getRawOriginal());
        $this->assertSame(1, $category->products()->count());
    }
}
