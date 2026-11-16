<?php

namespace Tests\Feature\Seller;

use App\Models\Catalog\Product;
use App\Services\Seller\ProductThumbnailService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductThumbnailTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD with WebP support is required.');
        }
        Storage::fake('public');
    }

    public function test_generate_shrinks_large_images_and_keeps_small_ones_at_size(): void
    {
        $large = UploadedFile::fake()->image('large.jpg', 2000, 1000)->store('products/1', 'public');
        $small = UploadedFile::fake()->image('small.jpg', 100, 100)->store('products/1', 'public');
        $service = app(ProductThumbnailService::class);

        $largeSize = getimagesizefromstring(Storage::disk('public')->get($service->generate($large)));
        $smallSize = getimagesizefromstring(Storage::disk('public')->get($service->generate($small)));

        $this->assertSame([640, 320, 'image/webp'], [$largeSize[0], $largeSize[1], $largeSize['mime']]);
        $this->assertSame([100, 100], [$smallSize[0], $smallSize[1]]);
    }

    public function test_generate_returns_null_for_a_file_that_is_not_an_image(): void
    {
        Storage::disk('public')->put('products/1/broken.jpg', 'not an image');

        $this->assertNull(app(ProductThumbnailService::class)->generate('products/1/broken.jpg'));
    }

    public function test_card_uses_thumbnail_and_falls_back_to_original(): void
    {
        $product = Product::factory()->create();
        $image = $product->images()->create(['path' => 'products/1/a.jpg', 'is_primary' => true, 'sort_order' => 1]);

        $this->assertStringEndsWith('products/1/a.jpg', $product->load('images')->catalogImageUrl(requireExistingFile: false, thumbnail: true));

        $image->update(['thumbnail_path' => 'thumbnails/products/1/a.jpg.webp']);

        $url = $product->load('images')->catalogImageUrl(requireExistingFile: false, thumbnail: true);
        $this->assertStringEndsWith('thumbnails/products/1/a.jpg.webp', $url);
    }

    public function test_command_backfills_every_row_sharing_a_file(): void
    {
        $path = UploadedFile::fake()->image('a.jpg', 800, 800)->store('products/1', 'public');
        $product = Product::factory()->create();
        $product->images()->create(['path' => $path, 'sort_order' => 1]);
        $product->images()->create(['path' => $path, 'sort_order' => 2]);

        $this->artisan('products:generate-thumbnails')->assertSuccessful();

        $thumbnails = $product->images()->pluck('thumbnail_path')->unique();
        $this->assertCount(1, $thumbnails);
        Storage::disk('public')->assertExists($thumbnails->first());
    }
}
