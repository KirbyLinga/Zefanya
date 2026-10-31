<?php

namespace Tests\Feature\Http\Controllers\Seller;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductImage;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Services\Seller\ProductPublishingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ValidProductPublication;
use Tests\TestCase;

class ProductPublishingTest extends TestCase
{
    use LazilyRefreshDatabase;
    use ValidProductPublication;

    private function image(string $name = 'shirt.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    private function payload(): array
    {
        $rows = [];
        foreach (['Black', 'White', 'Beige'] as $color) {
            foreach (['S', 'M', 'L', 'XL'] as $size) {
                $index = count($rows);
                $rows[] = ['values' => [$color, $size], 'sku' => 'TEE-'.$index, 'price' => 350 + $index, 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'stock_quantity' => $index < 10 ? 8 : 7, 'is_available' => '1'];
            }
        }

        return [
            ...$this->publicationDefaults(),
            'name' => 'Premium Oversized Cotton Shirt', 'description' => 'Premium cotton oversized shirt, available in multiple colors and sizes.',
            'price' => 999, 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'stock_quantity' => 0, 'status' => 'active', 'variants_exist' => '1',
            'images' => [$this->image('detail.png'), $this->image('cover.png')], 'cover_index' => '1',
            'options' => [['name' => 'Color', 'values' => ['Black', 'White', 'Beige']], ['name' => 'Size', 'values' => ['S', 'M', 'L', 'XL']]],
            'variants' => $rows,
            'color_images' => [
                ['value' => 'Black', 'files' => [$this->image('black.png')]],
                ['value' => 'White', 'files' => [$this->image('white.png')]],
                ['value' => 'Beige', 'files' => [$this->image('beige.png')]],
            ],
        ];
    }

    public function test_publishing_saves_one_listing_twelve_combinations_and_shared_color_images(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();

        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect(route('seller.products.index'));

        $product = Product::query()->sole();
        $this->assertSame($seller->line_of_business_id ?? Category::query()->where('label', 'Home & Living')->value('id'), $product->category_id);
        $this->assertSame(12, $product->variants()->count());
        $this->assertSame(94, $product->stock_quantity);
        $this->assertSame('350.00', $product->price);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        $this->assertSame(2, $product->images()->whereNull('variant_id')->count());
        $this->assertSame(12, $product->images()->whereNotNull('variant_id')->count());
        $this->assertCount(5, Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('inventory_transactions', 12);
        foreach (['Black', 'White', 'Beige'] as $color) {
            $paths = $product->variants()->whereHas('optionValues', fn ($query) => $query->where('value', $color))
                ->with('images')->get()->flatMap(fn ($variant) => $variant->images->pluck('path'));
            $this->assertCount(4, $paths);
            $this->assertCount(1, $paths->unique());
            Storage::disk('public')->assertExists($paths->first());
        }
        $general = $product->images()->whereNull('variant_id')->orderBy('sort_order')->get();
        $this->assertTrue($general[1]->is_primary);
        $this->assertFalse($general[0]->is_primary);

        $this->get(route('buyer.products.show', $product))->assertViewHas('variantData', function ($rows) {
            $this->assertCount(12, $rows);
            $this->assertCount(3, collect($rows)->pluck('images.0')->unique());
            $this->assertSame(0, $rows[0]['color_axis']);

            return true;
        })->assertViewHas('galleryImages', fn ($images) => count($images) === 5);
    }

    public static function invalidCombinations(): array
    {
        return [
            'duplicate combination' => ['duplicate', 'variants.1.values'],
            'missing combination' => ['missing', 'variants'],
            'foreign value' => ['unknown', 'variants.0.values'],
            'duplicate option value' => ['duplicate-value', 'options.0.values'],
            'negative price' => ['price', 'variants.0.price'],
            'negative stock' => ['stock', 'variants.0.stock_quantity'],
            'fractional stock' => ['fractional', 'variants.0.stock_quantity'],
            'unknown color upload' => ['color', 'color_images.0.value'],
            'cover outside upload list' => ['cover', 'cover_index'],
            'more than twenty options in one axis' => ['limit', 'options.0.values'],
        ];
    }

    #[DataProvider('invalidCombinations')]
    public function test_invalid_inventory_does_not_create_a_listing(string $case, string $field): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $data = $this->payload();
        switch ($case) {
            case 'duplicate': $data['variants'][1]['values'] = ['Black', 'S'];
                break;
            case 'missing': array_pop($data['variants']);
                break;
            case 'unknown': $data['variants'][0]['values'] = ['Red', 'S'];
                break;
            case 'duplicate-value': $data['options'][0]['values'][] = 'black';
                break;
            case 'price': $data['variants'][0]['price'] = -1;
                break;
            case 'stock': $data['variants'][0]['stock_quantity'] = -1;
                break;
            case 'fractional': $data['variants'][0]['stock_quantity'] = 1.5;
                break;
            case 'color': $data['color_images'][0]['value'] = 'Red';
                break;
            case 'cover': $data['cover_index'] = 8;
                break;
            case 'limit':
                $data['options'] = [['name' => 'Color', 'values' => range(1, 21)]];
                $data['variants'] = array_map(fn ($i) => ['values' => [(string) $i], 'price' => 50, 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'stock_quantity' => 1], range(1, 21));
                unset($data['color_images']);
                break;
        }

        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $data)->assertSessionHasErrors($field);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_edits_preserve_variant_ids_cart_lines_images_and_existing_cover(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = app(ProductPublishingService::class)->save(new Product, $seller, $this->payload());
        $ids = $product->variants()->orderBy('id')->pluck('id')->all();
        $photos = $product->images()->whereNotNull('variant_id')->pluck('variant_id', 'id')->all();
        $cover = $product->images()->where('is_primary', true)->value('path');
        $cart = CartItem::query()->create(['buyer_id' => Buyer::factory()->create()->id, 'product_id' => $product->id, 'variant_id' => $ids[0], 'quantity' => 1]);
        $data = $this->payload();
        unset($data['color_images'], $data['cover_index']);
        $data['variants'][0]['stock_quantity'] = 2;

        $this->actingAs($seller, 'seller')->put(route('seller.products.update', $product), $data + ['inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants'))])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($ids, $product->variants()->orderBy('id')->pluck('id')->all());
        $this->assertModelExists($cart);
        $this->assertSame($photos, $product->images()->whereNotNull('variant_id')->pluck('variant_id', 'id')->all());
        $this->assertSame($cover, $product->images()->where('is_primary', true)->value('path'));
        $this->assertSame(88, $product->fresh()->stock_quantity);
    }

    public function test_deleting_one_color_image_keeps_the_shared_file_for_other_sizes(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = app(ProductPublishingService::class)->save(new Product, $seller, $this->payload());
        $image = $product->images()->whereNotNull('variant_id')->firstOrFail();

        app(ProductPublishingService::class)->removeImage($product, $image);

        $this->assertModelMissing($image);
        $this->assertSame(3, ProductImage::query()->where('path', $image->path)->count());
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_variant_uploads_cannot_reference_another_sellers_product(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $foreign = Product::factory()->create()->variants()->firstOrFail();
        $data = [...$this->publicationDefaults(), 'name' => $product->name, 'price' => 100, 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'stock_quantity' => 2, 'status' => 'active', 'variant_images' => [$foreign->id => [$this->image()]]];

        $this->actingAs($seller, 'seller')->put(route('seller.products.update', $product), $data + ['inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants'))])->assertSessionHasErrors('variant_images');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_color_uploads_reject_non_image_content(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $data = $this->payload();
        $data['color_images'][0]['files'] = [UploadedFile::fake()->createWithContent('unsafe.txt', 'This is not image content.')];

        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $data)->assertSessionHasErrors('color_images.0.files.0');

        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_failed_image_persistence_rolls_back_listing_inventory_and_files(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $data = $this->payload();
        $inserts = 0;
        DB::listen(function ($query) use (&$inserts): void {
            if (str_contains($query->sql, 'insert into "product_images"') && ++$inserts === 2) {
                throw new \RuntimeException('Simulated image persistence failure');
            }
        });

        try {
            app(ProductPublishingService::class)->save(new Product, $seller, $data);
            $this->fail('The simulated persistence error must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated image persistence failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('product_images', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_new_sizes_inherit_existing_color_photos_without_reupload(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = app(ProductPublishingService::class)->save(new Product, $seller, $this->payload());
        $data = $this->payload();
        unset($data['images'], $data['color_images'], $data['cover_index']);
        $data['options'][1]['values'][] = 'XXL';
        foreach (['Black', 'White', 'Beige'] as $index => $color) {
            $data['variants'][] = ['values' => [$color, 'XXL'], 'sku' => 'EXTRA-'.$index, 'price' => 400, 'weight' => 250, 'weight_unit' => 'g', 'weight_grams' => 250, 'stock_quantity' => 2, 'is_available' => '1'];
        }

        $this->actingAs($seller, 'seller')->put(route('seller.products.update', $product), $data + ['inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants'))])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(15, $product->variants()->count());
        $newSizes = $product->variants()->whereHas('optionValues', fn ($query) => $query->where('value', 'XXL'))->with('images')->get();
        $this->assertCount(3, $newSizes);
        foreach ($newSizes as $variant) {
            $this->assertCount(1, $variant->images);
            Storage::disk('public')->assertExists($variant->images->first()->path);
        }
        $this->assertCount(5, Storage::disk('public')->allFiles());
        $this->assertSame(100, $product->fresh()->stock_quantity);
    }
}
