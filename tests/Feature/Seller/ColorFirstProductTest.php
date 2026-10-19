<?php

namespace Tests\Feature\Seller;

use App\Models\Product;
use App\Models\Seller\Seller;
use App\Services\ProductPublishingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ColorFirstProductTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    private function payload(): array
    {
        return [
            'name' => 'Color-first shirt', 'price' => 299, 'stock_quantity' => 0,
            'status' => 'active', 'variants_exist' => 1, 'color_first' => 1,
            'images' => [$this->image('cover.png')],
            'options' => [
                ['name' => 'Color', 'values' => ['Black', 'Green', 'Navy']],
                ['name' => 'Size', 'values' => ['S', 'M', 'L', 'XL']],
            ],
            'variants' => collect(['Black' => ['S', 'M', 'L'], 'Green' => ['S', 'M', 'L', 'XL'], 'Navy' => ['M', 'L', 'XL']])
                ->flatMap(fn ($sizes, $color) => array_map(fn ($size) => [
                    'values' => [$color, $size], 'price' => $size === 'XL' ? 349 : 299,
                    'stock_quantity' => 3, 'sku' => "$color-$size", 'is_available' => 1,
                ], $sizes))->values()->all(),
            'color_images' => array_map(fn ($color) => ['value' => $color, 'files' => [$this->image("$color.png")]], ['Black', 'Green', 'Navy']),
        ];
    }

    private function publish(Seller $seller): Product
    {
        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $this->payload())->assertSessionHasNoErrors();

        return Product::query()->sole();
    }

    private function editPayload(Product $product): array
    {
        $data = $this->payload();
        unset($data['images'], $data['color_images']);
        $product->load(['variants.optionValues.option', 'options.values']);
        $data['inventory_revision'] = app(ProductPublishingService::class)->inventoryRevision($product);
        $data['variants'] = $product->variants->map(fn ($variant) => [
            'id' => $variant->id, 'values' => $product->options->map(fn ($option) => $variant->optionValues->firstWhere('product_option_id', $option->id)->value)->all(),
            'price' => $variant->price, 'stock_quantity' => $variant->stock_quantity,
            'sku' => $variant->sku, 'is_available' => $variant->is_available,
        ])->all();

        return $data;
    }

    public function test_sparse_color_sizes_publish_as_one_listing_with_explicit_shared_images(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $this->assertSame(10, $product->variants()->count());
        $this->assertSame(30, $product->stock_quantity);
        $this->assertFalse($product->images()->where('sort_order', '<', 0)->exists());
        $this->assertCount(4, Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('inventory_transactions', 10);
        $this->get(route('buyer.products.show', $product))->assertOk()->assertViewHas('variantData', function ($rows) {
            $this->assertCount(10, $rows);
            $black = collect($rows)->where('values.0', 'Black');
            $this->assertCount(3, $black);
            $this->assertCount(1, $black->pluck('images.0')->unique());
            $this->assertFalse($black->contains(fn ($row) => $row['values'][1] === 'XL'));

            return true;
        });
    }

    public function test_cover_photo_does_not_satisfy_a_missing_color_photo(): void
    {
        Storage::fake('public');
        $data = $this->payload();
        unset($data['color_images'][1]);
        $this->actingAs(Seller::factory()->create(), 'seller')->post(route('seller.products.store'), $data)
            ->assertSessionHasErrors('color_images');
        $this->assertDatabaseCount('products', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_renaming_a_color_keeps_ids_stock_ledger_and_saved_images(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $before = $product->variants()->orderBy('id')->get(['id', 'stock_quantity'])->toArray();
        $images = $product->images()->orderBy('id')->get(['id', 'path', 'variant_id'])->toArray();
        $data = $this->editPayload($product);
        $data['options'][0]['values'][0] = 'Charcoal';
        foreach ($data['variants'] as &$row) {
            if ($row['values'][0] === 'Black') {
                $row['values'][0] = 'Charcoal';
            }
        }
        unset($row);
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasNoErrors();
        $this->assertSame($before, $product->variants()->orderBy('id')->get(['id', 'stock_quantity'])->toArray());
        $this->assertSame($images, $product->images()->orderBy('id')->get(['id', 'path', 'variant_id'])->toArray());
        $this->assertDatabaseCount('inventory_transactions', 10);
        $this->assertSame(3, $product->variants()->whereHas('optionValues', fn ($query) => $query->where('value', 'Charcoal'))->count());
    }

    public function test_added_size_inherits_color_photo_and_new_upload_takes_priority_without_deleting_saved_files(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $oldFiles = Storage::disk('public')->allFiles();
        $data = $this->editPayload($product);
        $data['variants'][] = ['values' => ['Black', 'XL'], 'price' => 349, 'stock_quantity' => 4, 'is_available' => 1];
        $data['color_images'] = [['value' => 'Black', 'files' => [$this->image('new-black.png')]]];
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasNoErrors();
        $this->assertSame(11, $product->variants()->count());
        $this->assertSame(34, $product->fresh()->stock_quantity);
        $this->assertFalse($product->images()->where('sort_order', '<', 0)->exists());
        $this->get(route('buyer.products.show', $product))->assertViewHas('variantData', function ($rows) use ($oldFiles) {
            $photos = collect($rows)->where('values.0', 'Black')->pluck('images.0');
            $this->assertCount(4, $photos);
            $this->assertCount(1, $photos->unique());
            foreach ($oldFiles as $path) {
                $this->assertStringNotContainsString($path, $photos->first());
            }

            return true;
        });
        foreach ($oldFiles as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_size_label_edit_retains_inventory_and_images_for_the_same_variant(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $data = $this->editPayload($product);
        $row = collect($data['variants'])->search(fn ($row) => $row['values'] === ['Black', 'M']);
        $id = $data['variants'][$row]['id'];
        $paths = $product->images()->where('variant_id', $id)->pluck('path')->all();
        $data['options'][1]['values'][] = 'Medium';
        $data['variants'][$row]['values'][1] = 'Medium';
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasNoErrors();
        $this->assertSame(3, $product->variants()->findOrFail($id)->stock_quantity);
        $this->assertSame($paths, $product->images()->where('variant_id', $id)->pluck('path')->all());
        $this->assertDatabaseCount('inventory_transactions', 10);
        $this->get(route('seller.products.edit', $product), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('data-pf-color-first="1"', false);
    }

    public function test_saved_color_photo_can_be_replaced_for_all_sizes_without_inventory_changes(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $before = $product->variants()->orderBy('id')->get(['id', 'stock_quantity'])->toArray();
        $image = $product->variants()->whereHas('optionValues', fn ($query) => $query->where('value', 'Black'))->firstOrFail()->images()->firstOrFail();
        $otherPaths = $product->images()->where('path', '!=', $image->path)->pluck('path')->unique()->all();
        $data = $this->editPayload($product);
        $data['color_images'] = [['value' => 'Black', 'remove_ids' => [$image->id], 'files' => [$this->image('replacement.png')]]];
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasNoErrors();
        $this->assertFalse($product->images()->where('path', $image->path)->exists());
        $this->assertSame($before, $product->variants()->orderBy('id')->get(['id', 'stock_quantity'])->toArray());
        foreach ($otherPaths as $path) {
            $this->assertTrue($product->images()->where('path', $path)->exists());
        }
        $this->assertDatabaseCount('inventory_transactions', 10);
        $this->get(route('buyer.products.show', $product))->assertViewHas('variantData', fn ($rows) => collect($rows)->where('values.0', 'Black')->every(fn ($row) => count($row['images']) === 1 && ! str_contains($row['images'][0], $image->path)));
    }

    public function test_removing_last_color_photo_requires_a_replacement_and_preserves_saved_photo_on_error(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $image = $product->variants()->whereHas('optionValues', fn ($query) => $query->where('value', 'Black'))->firstOrFail()->images()->firstOrFail();
        $data = $this->editPayload($product);
        $data['color_images'] = [['value' => 'Black', 'remove_ids' => [$image->id]]];
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasErrors('color_images');
        $this->assertTrue($product->images()->whereKey($image->id)->exists());
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_other_color_or_other_product_photos_cannot_be_removed(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $wrongColor = $product->variants()->whereHas('optionValues', fn ($query) => $query->where('value', 'Green'))->firstOrFail()->images()->firstOrFail();
        $data = $this->editPayload($product);
        $data['color_images'] = [['value' => 'Black', 'remove_ids' => [$wrongColor->id], 'files' => [$this->image('replacement.png')]]];
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasErrors('color_images.0.remove_ids');
        $this->assertTrue($product->images()->whereKey($wrongColor->id)->exists());
        $other = Product::factory()->create();
        $foreign = $other->images()->create(['variant_id' => $other->variants()->firstOrFail()->id, 'path' => 'foreign.png', 'is_primary' => false, 'sort_order' => 0]);
        $data['color_images'][0]['remove_ids'] = [$foreign->id];
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasErrors('color_images.0.remove_ids');
        $this->assertTrue($other->images()->whereKey($foreign->id)->exists());
    }

    public function test_duplicate_and_foreign_saved_ids_are_rejected_without_inventory_changes(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = $this->publish($seller);
        $data = $this->editPayload($product);
        $data['variants'][1]['id'] = $data['variants'][0]['id'];
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasErrors('variants');
        $data = $this->editPayload($product);
        $other = Product::factory()->create();
        $data['variants'][0]['id'] = $other->variants()->firstOrFail()->id;
        $this->put(route('seller.products.update', $product), $data)->assertSessionHasErrors('variants');
        $this->assertSame(30, $product->fresh()->stock_quantity);
        $this->assertSame(10, DB::table('inventory_transactions')->where('product_id', $product->id)->count());
    }
}
