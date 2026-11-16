<?php

namespace Tests\Feature\Buyer;

use App\Models\Catalog\Product;
use App\Services\Buyer\VisualCatalogIndex;
use App\Services\Buyer\VisualSearchService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisualSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->directory = storage_path('framework/testing/visual-search-'.Str::uuid());
        Storage::set('public', Storage::build(['driver' => 'local', 'root' => $this->directory.'/images']));
        config(['visual-search.enabled' => true, 'visual-search.index_path' => $this->directory.'/catalog.json']);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with($this->directory, storage_path('framework/testing/visual-search-'))) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function bytes(array $rgb = [235, 65, 140], string $format = 'png'): string
    {
        $image = imagecreatetruecolor(96, 96);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        imagerectangle($image, 18, 12, 76, 84, imagecolorallocate($image, 25, 25, 25));
        imageline($image, 18, 12, 76, 84, imagecolorallocate($image, 25, 25, 25));
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($image, null, 90),
            'webp' => imagewebp($image, null, 90),
            default => imagepng($image),
        };
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function product(string $name = 'Pink visual test', array $rgb = [235, 65, 140]): Product
    {
        $product = Product::factory()->create(['name' => $name, 'status' => 'active', 'stock_quantity' => 10]);
        $path = 'products/'.$product->id.'/photo.png';
        Storage::disk('public')->put($path, $this->bytes($rgb));
        $product->images()->create(['path' => $path, 'is_primary' => true]);

        return $product;
    }

    private function upload(array $rgb = [235, 65, 140], string $format = 'png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('query.'.$format, $this->bytes($rgb, $format));
    }

    public function test_page_and_navbar_provide_an_accessible_upload_without_changing_text_search(): void
    {
        $this->get(route('buyer.visual-search.index'))->assertOk()->assertSee('Search by image')
            ->assertSee('type="file"', false)->assertSee('JPEG, PNG or WebP')->assertSee('not semantic AI');
        $this->get(route('buyer.products.index'))->assertOk()->assertSee('aria-label="Search by image"', false)
            ->assertSee('placeholder="Search for products', false);
    }

    public static function formats(): array
    {
        return [['png'], ['jpeg'], ['webp']];
    }

    #[DataProvider('formats')]
    public function test_real_image_formats_return_existing_product_cards(string $format): void
    {
        $product = $this->product();
        $this->artisan('visual-search:index')->assertSuccessful();
        $response = $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload(format: $format)])
            ->assertOk()->assertJsonPath('count', 1);
        $this->assertStringContainsString('data-product-id="'.$product->id.'"', $response->json('html'));
        $this->assertStringContainsString(route('buyer.products.show', $product), $response->json('html'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_ranking_uses_the_closest_photo_and_deduplicates_products(): void
    {
        $match = $this->product('Matching pink');
        $this->product('Blue alternative', [15, 40, 235]);
        Storage::disk('public')->put('products/extra.png', $this->bytes());
        $match->images()->create(['path' => 'products/extra.png']);
        $service = app(VisualSearchService::class);
        $service->rebuild();
        $file = $this->upload();
        $this->assertSame([$match->id], $service->search($file->getPathname())['products']->modelKeys());
    }

    public function test_similarity_result_limit_is_bounded(): void
    {
        for ($i = 0; $i < 18; $i++) {
            $this->product('Pink '.$i);
        }
        app(VisualSearchService::class)->rebuild();
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])->assertOk()->assertJsonPath('count', 16);
    }

    public function test_pending_rejected_restricted_and_deleted_products_are_excluded_from_the_index(): void
    {
        $valid = $this->product('Public');
        foreach (['pending', 'rejected', 'inactive', 'draft'] as $status) {
            $this->product($status)->update(['status' => $status]);
        }
        $this->product('Restricted')->update(['restriction_reason' => 'Under review']);
        $this->product('Deleted')->delete();
        $result = app(VisualSearchService::class)->rebuild();
        $this->assertSame(1, $result['indexed']);
        $upload = $this->upload();
        $this->assertSame([$valid->id], app(VisualSearchService::class)->search($upload->getPathname())['products']->modelKeys());
    }

    public static function sellerStates(): array
    {
        return [['status', 'suspended'], ['status', 'pending'], ['is_on_vacation', true], ['archived_at', '2026-01-01 00:00:00']];
    }

    #[DataProvider('sellerStates')]
    public function test_visibility_is_rechecked_after_a_seller_becomes_unavailable(string $attribute, mixed $value): void
    {
        $product = $this->product();
        app(VisualSearchService::class)->rebuild();
        $product->seller->forceFill([$attribute => $value])->save();
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])->assertOk()->assertJsonPath('count', 0);
    }

    public function test_stock_and_product_visibility_are_rechecked_without_reindexing(): void
    {
        $product = $this->product();
        app(VisualSearchService::class)->rebuild();
        $product->variants()->update(['stock_quantity' => 0]);
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])->assertOk()->assertJsonPath('count', 0);
        $product->variants()->update(['stock_quantity' => 10]);
        $product->update(['status' => 'pending']);
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])->assertOk()->assertJsonPath('count', 0);
    }

    public function test_invalid_images_and_file_paths_cannot_be_searched(): void
    {
        $this->postJson(route('buyer.visual-search.search'), ['url' => 'http://127.0.0.1/private'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->postJson(route('buyer.visual-search.search'), ['image' => UploadedFile::fake()->createWithContent('bad.png', 'not an image')])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->postJson(route('buyer.visual-search.search'), ['image' => UploadedFile::fake()->create('large.png', 5121, 'image/png')])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->postJson(route('buyer.visual-search.search'), ['image' => UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        foreach (['../secret.png', 'https://example.com/photo.png', 'C:/secret.png', '/etc/passwd', 'products/../photo.png'] as $path) {
            $this->assertNull(app(VisualSearchService::class)->localImage($path));
        }
    }

    public function test_an_image_that_decodes_beyond_the_pixel_limit_is_rejected(): void
    {
        config(['visual-search.max_pixels' => 100]);
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_empty_catalog_and_no_visual_matches_are_honest_empty_states(): void
    {
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])->assertOk()->assertJsonPath('count', 0);
        $this->product('Blue', [15, 40, 235]);
        app(VisualSearchService::class)->rebuild();
        $response = $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])
            ->assertOk()->assertJsonPath('count', 0);
        $this->assertStringContainsString('No similar products found', $response->json('html'));
    }

    public function test_missing_index_and_unavailable_engine_fail_gracefully(): void
    {
        $this->product();
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])->assertStatus(503);
        config(['visual-search.enabled' => false]);
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])->assertStatus(503);
    }

    public function test_corrupt_cached_features_fail_gracefully_and_can_be_rebuilt(): void
    {
        $this->product();
        $service = app(VisualSearchService::class);
        $service->rebuild();
        app(VisualCatalogIndex::class)->database()->exec("UPDATE features SET data='{}'");
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])->assertStatus(503);
        $this->assertSame(1, $service->rebuild()['indexed']);
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])->assertOk();
    }

    public function test_unchanged_photos_reuse_features_and_changed_or_deleted_files_are_not_returned(): void
    {
        $product = $this->product();
        $service = app(VisualSearchService::class);
        $service->rebuild();
        $this->assertSame(1, $service->rebuild()['reused']);
        $path = Storage::disk('public')->path($product->images()->first()->path);
        touch($path, time() + 5);
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])->assertStatus(503);
        $this->assertSame(0, $service->rebuild()['reused']);
        unlink($path);
        $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload()])->assertStatus(503);
    }

    public function test_reindex_is_safe_under_concurrent_calls_and_preserves_previous_index_on_limit(): void
    {
        $this->product();
        $service = app(VisualSearchService::class);
        $service->rebuild();
        $before = app(VisualCatalogIndex::class)->database()->query('SELECT * FROM images')->fetchAll();
        $lock = fopen(config('visual-search.index_path').'.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->artisan('visual-search:index')->assertFailed();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        config(['visual-search.max_catalog_images' => 0]);
        $this->artisan('visual-search:index')->assertFailed();
        $this->assertSame($before, app(VisualCatalogIndex::class)->database()->query('SELECT * FROM images')->fetchAll());
    }

    public function test_request_throttle_is_present_and_enforced(): void
    {
        $this->assertContains('throttle:10,1', app('router')->getRoutes()->getByName('buyer.visual-search.search')->gatherMiddleware());
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('buyer.visual-search.search'), [])->assertUnprocessable();
        }
        $this->postJson(route('buyer.visual-search.search'), [])->assertTooManyRequests();
    }
}
