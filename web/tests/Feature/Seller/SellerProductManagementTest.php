<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\OrderItem;
use App\Services\Seller\ProductPublishingService;
use App\Services\Seller\StoreCategoryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ValidProductPublication;
use Tests\TestCase;

class SellerProductManagementTest extends TestCase
{
    use LazilyRefreshDatabase, ValidProductPublication;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function payload(array $changes = []): array
    {
        return array_replace($this->publicationDefaults(), [
            'name' => 'Buri Placemat Set', 'description' => 'Set of 6 placemats, handwoven buri palm.',
            'price' => '299.00', 'stock_quantity' => 30, 'weight' => '0.6', 'weight_unit' => 'kg',
            'status' => 'active', 'brand' => 'No brand', 'condition' => 'New',
        ], $changes);
    }

    private function publish(Seller $seller, array $changes = []): Product
    {
        $data = $this->payload($changes);
        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $data)->assertSessionHasNoErrors();

        return Product::query()->where('creation_key', $data['creation_key'])->firstOrFail();
    }

    public function test_sel_prod_001_saves_all_fields_and_store_category_in_uploaded_order(): void
    {
        $seller = Seller::factory()->create();
        $category = app(StoreCategoryService::class)->save($seller, ['name' => 'Kitchen & Dining']);
        $first = $this->publicationDefaults()['images'][0];
        $second = $this->publicationDefaults()['images'][0];
        $product = $this->publish($seller, ['images' => [$first, $second], 'store_category_ids' => [$category->id]]);

        $this->assertSame('active', $product->status);
        $this->assertSame('299.00', $product->price);
        $this->assertSame(30, $product->stock_quantity);
        $this->assertSame(600, $product->weight_grams);
        $this->assertSame(['length' => 35, 'width' => 25, 'height' => 3], $product->dimensions);
        $this->assertSame('No brand', $product->brand);
        $this->assertSame('New', $product->condition);
        $this->assertSame([$category->id], $product->storeCategories->modelKeys());
        $paths = $product->images()->pluck('path')->all();
        $this->assertStringEndsWith($first->hashName(), $paths[0]);
        $this->assertStringEndsWith($second->hashName(), $paths[1]);
        $this->get(route('buyer.products.index', ['q' => $product->name]))->assertSee($product->name);
        $this->get(route('buyer.products.show', $product))->assertOk()->assertSee($product->name);
    }

    public static function requiredFields(): array
    {
        return array_map(fn ($field) => [$field], ['name', 'description', 'images', 'price', 'stock_quantity', 'weight']);
    }

    public function test_add_product_is_four_guided_steps_with_a_plain_choice_about_versions(): void
    {
        $this->actingAs(Seller::factory()->create(), 'seller')->get(route('seller.products.index'))->assertOk()
            ->assertSeeInOrder(['Details', 'Photos and versions', 'Price and stock', 'Review'])
            ->assertSee('data-pf-progress="4"', false)
            ->assertDontSee('data-pf-step="5"', false)
            ->assertSee('Does this product come in different versions?')
            ->assertSee('No, just one version')
            ->assertSee('Yes, different colors or sizes')
            ->assertSee('data-pf-bulk-weight', false);
    }

    #[DataProvider('requiredFields')]
    public function test_sel_prod_002_missing_required_fields_create_nothing(string $field): void
    {
        $seller = Seller::factory()->create();
        $data = $this->payload();
        unset($data[$field]);
        $this->actingAs($seller, 'seller')->postJson(route('seller.products.store'), $data)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public static function fieldBoundaries(): array
    {
        return [
            ['name', 'Tote', false], ['name', 'Totes', true], ['name', str_repeat('a', 120), true], ['name', str_repeat('a', 121), false],
            ['description', str_repeat('a', 19), false], ['description', str_repeat('a', 20), true], ['description', str_repeat('a', 3000), true], ['description', str_repeat('a', 3001), false],
            ['price', '0', false], ['price', '-50', false], ['price', '0.99', false], ['price', '1.00', true], ['price', '1.01', true],
            ['price', '199.999', false], ['price', '999999.99', true], ['price', '1000000.00', true], ['price', '1000000.01', false], ['price', 'abc', false],
            ['stock_quantity', -1, false], ['stock_quantity', 0, true], ['stock_quantity', 1, true], ['stock_quantity', 99999, true], ['stock_quantity', 100000, false], ['stock_quantity', 2.5, false],
        ];
    }

    #[DataProvider('fieldBoundaries')]
    public function test_sel_prod_003_004_005_exact_publication_boundaries(string $field, mixed $value, bool $valid): void
    {
        $seller = Seller::factory()->create();
        $data = $this->payload([$field => $value]);
        $response = $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $data);
        if (! $valid) {
            $response->assertSessionHasErrors($field);
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('inventory_transactions', 0);

            return;
        }
        $response->assertSessionHasNoErrors();
        $product = Product::query()->sole();
        $this->assertSame('active', $product->status);
        $this->assertSame((string) $value, (string) $product->{$field});
        if ($field === 'stock_quantity' && $value === 0) {
            $this->get(route('buyer.products.show', $product))->assertSee('Out of Stock');
        }
    }

    public static function imageCounts(): array
    {
        return [[0, false], [1, true], [9, true], [10, false]];
    }

    #[DataProvider('imageCounts')]
    public function test_sel_prod_006_image_count_limits(int $count, bool $valid): void
    {
        $seller = Seller::factory()->create();
        $data = $this->payload(['images' => array_map(fn () => $this->publicationDefaults()['images'][0], range(1, max(1, $count)))]);
        if ($count === 0) {
            $data['images'] = [];
        }
        $response = $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $data);
        if ($valid) {
            $response->assertSessionHasNoErrors();
            $this->assertSame($count, Product::query()->sole()->images()->count());
        } else {
            $response->assertSessionHasErrors('images');
            $this->assertDatabaseCount('products', 0);
        }
    }

    public function test_sel_prod_006_existing_images_count_towards_the_nine_image_limit(): void
    {
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $data = $this->payload(['images' => array_map(fn () => $this->publicationDefaults()['images'][0], range(1, 8))]);
        $data['inventory_revision'] = app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants'));
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasNoErrors();
        $this->assertSame(9, $product->images()->count());
        $data = $this->payload();
        $data['inventory_revision'] = app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants'));
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasErrors('images');
        $this->assertSame(9, $product->images()->count());
        $this->assertCount(9, Storage::disk('public')->allFiles());
    }

    public static function invalidImages(): array
    {
        return [['oversized'], ['document'], ['executable'], ['webp']];
    }

    #[DataProvider('invalidImages')]
    public function test_sel_prod_007_rejects_unsupported_or_oversized_uploads(string $case): void
    {
        $seller = Seller::factory()->create();
        $fakeExecutable = UploadedFile::fake()->createWithContent('bad.tmp', 'MZ executable content');
        $image = match ($case) {
            'oversized' => $this->publicationDefaults()['images'][0]->size(5223),
            'document' => UploadedFile::fake()->createWithContent('price_list.docx', 'document content'),
            'executable' => new UploadedFile($fakeExecutable->getPathname(), 'renamed_exe.jpg', null, null, true),
            'webp' => UploadedFile::fake()->create('photo.webp', 10, 'image/webp'),
        };
        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $this->payload(['images' => [$image]]))->assertSessionHasErrors('images.0');
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_sel_prod_007_accepts_an_image_at_exactly_five_megabytes(): void
    {
        $seller = Seller::factory()->create();
        $image = $this->publicationDefaults()['images'][0]->size(5120);
        $this->publish($seller, ['images' => [$image]]);
        $this->assertDatabaseCount('products', 1);
    }

    public static function invalidParcels(): array
    {
        return [['weight', 0], ['weight', -1], ['dimensions', ['length' => 0, 'width' => 0, 'height' => 0]], ['dimensions', 'abc'], ['dimensions', null]];
    }

    #[DataProvider('invalidParcels')]
    public function test_sel_prod_008_rejects_invalid_parcel_data(string $field, mixed $value): void
    {
        $seller = Seller::factory()->create();
        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $this->payload([$field => $value]))
            ->assertSessionHasErrors($field === 'weight' ? 'weight_grams' : ($field === 'dimensions' && is_array($value) ? 'dimensions.length' : $field));
        $this->assertDatabaseCount('products', 0);
    }

    public function test_sel_prod_008_accepts_point_zero_one_kg_without_altering_the_weight(): void
    {
        $product = $this->publish(Seller::factory()->create(), ['weight' => '0.01']);
        $this->assertSame(10, $product->weight_grams);
        $this->assertSame(10, $product->variants()->sole()->weight_grams);
    }

    public function test_sel_prod_009_and_010_name_only_draft_stays_private_and_cannot_be_published_incomplete(): void
    {
        $seller = Seller::factory()->create();
        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), [
            'name' => 'Draft Woven Fan', 'status' => 'draft', 'creation_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $product = Product::query()->sole();
        $this->assertSame('draft', $product->status);
        $this->get(route('buyer.products.index', ['q' => $product->name]))->assertViewHas('products', fn ($rows) => $rows->isEmpty());
        $this->get(route('buyer.products.show', $product))->assertNotFound();
        $this->patchJson(route('seller.products.toggleStatus', $product), ['status' => 'active'])->assertUnprocessable()->assertJsonValidationErrors(['description', 'price']);
        $this->assertSame('draft', $product->fresh()->status);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $data = $this->payload(['name' => $product->name]);
        $data['inventory_revision'] = app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants'));
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasNoErrors();
        $this->assertSame('active', $product->fresh()->status);
    }

    public function test_sel_prod_011_deactivation_and_reactivation_preserve_inventory_and_images(): void
    {
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $before = $product->variants()->get()->toArray();
        $paths = $product->images()->pluck('path')->all();
        $this->patchJson(route('seller.products.toggleStatus', $product), ['status' => 'inactive'])->assertOk();
        $this->get(route('buyer.products.show', $product))->assertNotFound();
        $this->get(route('buyer.products.index', ['q' => $product->name]))->assertViewHas('products', fn ($rows) => $rows->isEmpty());
        $this->patchJson(route('seller.products.toggleStatus', $product), ['status' => 'active'])->assertOk();
        $this->get(route('buyer.products.show', $product))->assertOk();
        $this->assertSame($before, $product->variants()->get()->toArray());
        $this->assertSame($paths, $product->images()->pluck('path')->all());
    }

    public function test_sel_prod_012_duplicate_is_a_draft_with_independent_inventory_and_unchanged_original(): void
    {
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $before = $product->getRawOriginal();
        $variant = $product->variants()->sole();
        $sourceLedger = InventoryTransaction::query()->where('product_id', $product->id)->get()->toArray();
        $this->post(route('seller.products.duplicate', $product))->assertRedirect();
        $copy = Product::query()->whereKeyNot($product->id)->sole();
        $this->assertSame('draft', $copy->status);
        $this->assertNotSame($product->slug, $copy->slug);
        $this->assertSame($product->name, $copy->name);
        $this->assertSame($product->price, $copy->price);
        $this->assertSame($product->stock_quantity, $copy->stock_quantity);
        $this->assertNotSame($variant->id, $copy->variants()->sole()->id);
        $this->assertSame($product->images()->pluck('path')->all(), $copy->images()->pluck('path')->all());
        $this->assertSame($before, $product->fresh()->getRawOriginal());
        $this->assertSame($sourceLedger, InventoryTransaction::query()->where('product_id', $product->id)->get()->toArray());
        $this->get(route('buyer.products.index', ['q' => $product->name]))->assertViewHas('products', fn ($rows) => $rows->count() === 1);
    }

    public function test_sel_prod_013_price_edit_updates_the_existing_variant_and_cart(): void
    {
        $seller = Seller::factory()->create();
        $product = $this->publish($seller, ['price' => '450.00']);
        $variant = $product->variants()->sole();
        $cart = CartItem::query()->create(['buyer_id' => Buyer::factory()->create()->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        $data = $this->payload(['price' => '420.00']);
        $data['inventory_revision'] = app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants'));
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasNoErrors();
        $this->assertSame($variant->id, $product->variants()->sole()->id);
        $this->assertSame('420.00', $cart->fresh()->variant->price);
        $this->assertSame('420.00', $product->fresh()->price);
    }

    public static function unfinishedStatuses(): array
    {
        return array_map(fn ($status) => [$status], array_filter(OrderStatus::cases(), fn ($status) => ! in_array($status, [OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Returned], true)));
    }

    #[DataProvider('unfinishedStatuses')]
    public function test_sel_prod_014_unfinished_orders_block_deletion(OrderStatus $status): void
    {
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $item = OrderItem::factory()->create(['product_id' => $product->id, 'variant_id' => $product->variants()->sole()->id]);
        $item->sellerOrder->forceFill(['status' => $status])->save();
        $this->deleteJson(route('seller.products.destroy', $product))->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->assertSame('active', $product->fresh()->status);
        $this->assertNotSoftDeleted($product);
    }

    public static function finishedStatuses(): array
    {
        return [[OrderStatus::Completed], [OrderStatus::Cancelled], [OrderStatus::Returned]];
    }

    #[DataProvider('finishedStatuses')]
    public function test_sel_prod_015_finished_order_snapshots_and_inventory_survive_archival(OrderStatus $status): void
    {
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $variant = $product->variants()->sole();
        $item = OrderItem::factory()->create(['product_id' => $product->id, 'variant_id' => $variant->id, 'product_name' => $product->name, 'price_minor' => 29900]);
        $item->sellerOrder->forceFill(['status' => $status])->save();
        $snapshot = $item->fresh()->getRawOriginal();
        $this->deleteJson(route('seller.products.destroy', $product))->assertOk();
        $this->assertSoftDeleted($product);
        $this->assertSame($snapshot, $item->fresh()->getRawOriginal());
        $this->assertSame($product->id, $item->fresh()->product->id);
        $this->assertSame(30, $variant->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->get(route('buyer.products.show', $product))->assertNotFound();
    }

    public function test_sel_prod_016_bulk_deactivation_and_mixed_deletion_report_skipped_products(): void
    {
        $seller = Seller::factory()->create();
        $busy = $this->publish($seller, ['name' => 'Busy Basket']);
        $free = $this->publish($seller, ['name' => 'Free Basket']);
        $other = $this->publish($seller, ['name' => 'Other Basket']);
        $item = OrderItem::factory()->create(['product_id' => $busy->id, 'variant_id' => $busy->variants()->sole()->id]);
        $item->sellerOrder->forceFill(['status' => OrderStatus::Preparing])->save();
        $ids = [$busy->id, $free->id, $other->id];
        $this->postJson(route('seller.products.bulk'), ['action' => 'inactivate', 'product_ids' => $ids])->assertOk();
        $this->assertSame(3, Product::query()->where('status', 'inactive')->count());
        $this->postJson(route('seller.products.bulk'), ['action' => 'activate', 'product_ids' => $ids])->assertOk();
        $this->postJson(route('seller.products.bulk'), ['action' => 'archive', 'product_ids' => $ids])->assertOk()->assertJsonPath('skipped', ['Busy Basket']);
        $this->assertNotSoftDeleted($busy);
        $this->assertSame('active', $busy->fresh()->status);
        $this->assertSoftDeleted($free);
        $this->assertSoftDeleted($other);
    }

    public function test_bulk_deletion_reports_each_skip_reason_and_only_archives_eligible_products(): void
    {
        $seller = Seller::factory()->create();
        $busy = $this->publish($seller, ['name' => 'Busy Basket']);
        $restricted = $this->publish($seller, ['name' => 'Restricted Basket']);
        $free = $this->publish($seller, ['name' => 'Free Basket']);
        $restricted->forceFill(['restricted_at' => now(), 'restriction_reason' => 'Admin review required.'])->save();
        $item = OrderItem::factory()->create(['product_id' => $busy->id, 'variant_id' => $busy->variants()->sole()->id]);
        $item->sellerOrder->forceFill(['status' => OrderStatus::Preparing])->save();
        $response = $this->postJson(route('seller.products.bulk'), [
            'action' => 'archive', 'product_ids' => [$busy->id, $restricted->id, $free->id],
        ])->assertOk()->assertJsonPath('skipped', ['Busy Basket', 'Restricted Basket (admin-restricted)']);
        $this->assertStringContainsString('Busy Basket (unfinished orders)', $response->json('message'));
        $this->assertStringContainsString('Restricted Basket (admin-restricted)', $response->json('message'));
        $this->assertStringNotContainsString('Skipped products with unfinished orders:', $response->json('message'));
        $this->assertNotSoftDeleted($busy);
        $this->assertNotSoftDeleted($restricted);
        $this->assertSoftDeleted($free);
        $this->assertModelExists($item);
    }

    public function test_a_finished_order_does_not_allow_deletion_while_another_order_is_unfinished(): void
    {
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        foreach ([OrderStatus::Completed, OrderStatus::Preparing] as $status) {
            $item = OrderItem::factory()->create(['product_id' => $product->id, 'variant_id' => $product->variants()->sole()->id]);
            $item->sellerOrder->forceFill(['status' => $status])->save();
        }
        $this->deleteJson(route('seller.products.destroy', $product))->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->assertNotSoftDeleted($product);
        $this->assertSame(2, OrderItem::where('product_id', $product->id)->count());
    }

    public function test_sel_prod_017_duplicate_publish_creates_one_product_variant_and_opening_ledger_entry(): void
    {
        $seller = Seller::factory()->create();
        $data = $this->payload();
        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('seller.products.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_sel_prod_018_foreign_product_and_bulk_actions_are_denied_without_changes(): void
    {
        $owner = Seller::factory()->create();
        $product = $this->publish($owner);
        $before = $product->getRawOriginal();
        $other = Seller::factory()->create();
        $this->actingAs($other, 'seller')->get(route('seller.products.edit', $product))->assertForbidden();
        $this->putJson(route('seller.products.update', $product), [])->assertForbidden();
        $this->post(route('seller.products.duplicate', $product))->assertForbidden();
        $this->postJson(route('seller.products.bulk'), ['action' => 'archive', 'product_ids' => [$product->id]])->assertForbidden();
        $this->deleteJson(route('seller.products.destroy', $product))->assertForbidden();
        $this->assertSame($before, $product->fresh()->getRawOriginal());
        $this->assertDatabaseCount('products', 1);
    }

    public static function otherRoles(): array
    {
        return [['buyer', Buyer::class], ['admin', Admin::class], ['logistics', LogisticsProvider::class], ['rider', Rider::class]];
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_manage_seller_products(string $guard, string $model): void
    {
        $product = $this->publish(Seller::factory()->create());
        auth('seller')->logout();
        $this->actingAs($model::factory()->create(), $guard);
        $this->postJson(route('seller.products.store'), [])->assertUnauthorized();
        $this->putJson(route('seller.products.update', $product), [])->assertUnauthorized();
        $this->postJson(route('seller.products.duplicate', $product))->assertUnauthorized();
        $this->postJson(route('seller.products.bulk'), ['action' => 'archive', 'product_ids' => [$product->id]])->assertUnauthorized();
        $this->assertDatabaseCount('products', 1);
    }

    public function test_guest_cannot_publish_and_publication_requires_a_submission_key(): void
    {
        $this->postJson(route('seller.products.store'), [])->assertUnauthorized();
        $seller = Seller::factory()->create();
        $data = $this->payload();
        unset($data['creation_key']);
        $this->actingAs($seller, 'seller')->postJson(route('seller.products.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('creation_key');
        $this->assertDatabaseCount('products', 0);
    }

    private function variationData(int $colors = 2): array
    {
        $values = array_map(fn ($i) => 'Color '.$i, range(1, $colors));

        return [
            'variants_exist' => '1', 'color_first' => '0',
            'options' => [['name' => 'Color', 'values' => $values]],
            'variants' => array_map(fn ($color) => ['values' => [$color], 'sku' => 'SKU-'.$color, 'price' => '299.00', 'stock_quantity' => 3, 'weight' => 600, 'weight_unit' => 'g'], $values),
            'color_images' => array_map(fn ($color) => ['value' => $color, 'files' => [$this->publicationDefaults()['images'][0]]], $values),
        ];
    }

    public function test_sel_prod_017_repeated_variation_publication_does_not_conflict_with_its_own_skus(): void
    {
        $seller = Seller::factory()->create();
        $data = $this->payload($this->variationData());
        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('seller.products.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertDatabaseCount('inventory_transactions', 2);
    }

    public function test_sel_prod_012_duplication_keeps_variation_prices_and_stocks_without_reusing_skus(): void
    {
        $seller = Seller::factory()->create();
        $product = $this->publish($seller, $this->variationData());
        $before = $product->variants()->orderBy('id')->get()->toArray();
        $this->post(route('seller.products.duplicate', $product))->assertRedirect();
        $copy = Product::query()->whereKeyNot($product->id)->sole();
        $this->assertSame('draft', $copy->status);
        $this->assertSame(2, $copy->variants()->count());
        $this->assertSame(6, $copy->stock_quantity);
        $this->assertSame(0, $copy->variants()->whereNotNull('sku')->count());
        $this->assertSame(2, $copy->images()->whereNotNull('variant_id')->count());
        $this->assertSame($before, $product->variants()->orderBy('id')->get()->toArray());
    }

    public function test_gallery_limit_does_not_remove_rule_06_twenty_color_support(): void
    {
        $product = $this->publish(Seller::factory()->create(), $this->variationData(20));
        $this->assertSame(20, $product->variants()->count());
        $this->assertSame(20, $product->images()->whereNotNull('variant_id')->count());
        $this->assertSame(1, $product->images()->whereNull('variant_id')->count());
    }

    public function test_mixed_foreign_bulk_selection_is_rejected_before_changing_any_product(): void
    {
        $seller = Seller::factory()->create();
        $mine = $this->publish($seller);
        $foreign = $this->publish(Seller::factory()->create());
        $this->actingAs($seller, 'seller')->postJson(route('seller.products.bulk'), ['action' => 'inactivate', 'product_ids' => [$mine->id, $foreign->id]])->assertForbidden();
        $this->assertSame('active', $mine->fresh()->status);
        $this->assertSame('active', $foreign->fresh()->status);
    }

    public function test_active_product_cannot_lose_its_final_gallery_photo(): void
    {
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $image = $product->images()->sole();
        $this->delete(route('seller.products.images.destroy', [$product, $image]))->assertSessionHasErrors('images');
        $this->assertModelExists($image);
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_legacy_product_limits_are_not_automatically_rewritten(): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'name' => str_repeat('x', 121), 'price' => '1000000.01']);
        $before = $product->fresh()->getRawOriginal();
        $this->actingAs($seller, 'seller')->get(route('seller.products.index'))->assertOk();
        $this->assertSame($before, $product->fresh()->getRawOriginal());
        $this->assertSame('active', $product->status);
    }

    public function test_stock_limit_applies_per_variant_without_an_unsupported_aggregate_cap(): void
    {
        $data = $this->variationData();
        foreach ($data['variants'] as &$variant) {
            $variant['stock_quantity'] = 99999;
        }
        unset($variant);
        $data['stock_quantity'] = 199998;
        $product = $this->publish(Seller::factory()->create(), $data);
        $this->assertSame(199998, $product->stock_quantity);
        $this->assertSame([99999, 99999], $product->variants()->orderBy('id')->pluck('stock_quantity')->all());
    }
}
