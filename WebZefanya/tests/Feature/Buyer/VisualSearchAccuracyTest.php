<?php

namespace Tests\Feature\Buyer;

use App\Models\Catalog\Product;
use App\Models\Shared\Category;
use App\Services\Buyer\VisualSearchService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisualSearchAccuracyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $directory;

    private array $products = [];

    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->directory = storage_path('framework/testing/visual-accuracy-'.Str::uuid());
        Storage::set('public', Storage::build(['driver' => 'local', 'root' => $this->directory.'/images']));
        config(['visual-search.index_path' => $this->directory.'/catalog.json']);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with($this->directory, storage_path('framework/testing/visual-accuracy-'))) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function catalog(): VisualSearchService
    {
        $rows = json_decode(File::get(base_path('tests/Fixtures/visual-search/manifest.json')), true);
        foreach ($rows as $row) {
            $category = $this->categories[$row['category']] ??= Category::create(['label' => 'Fixture category '.$row['category'], 'icon' => 'package']);
            $product = $this->products[$row['product']] ??= Product::factory()->create([
                'name' => $row['label'], 'category_id' => $category->id, 'status' => 'active', 'stock_quantity' => 10,
            ]);
            $path = 'products/'.$row['file'];
            Storage::disk('public')->put($path, File::get(base_path('tests/Fixtures/visual-search/'.$row['file'])));
            $product->images()->create(['path' => $path]);
        }
        $service = app(VisualSearchService::class);
        $service->rebuild();

        return $service;
    }

    private function queryPhoto(int $product, string $variation): string
    {
        $source = imagecreatefromjpeg(base_path('tests/Fixtures/visual-search/catalog-'.$product.'-0.jpg'));
        if ($variation === 'lighting') {
            // A real photo with reduced exposure, not a replacement color swatch.
            imagefilter($source, IMG_FILTER_BRIGHTNESS, -32);
        }
        if ($variation === 'background') {
            $padded = imagecreatetruecolor(imagesx($source) + 30, imagesy($source) + 30);
            imagefill($padded, 0, 0, imagecolorallocate($padded, 210, 220, 235));
            imagecopy($padded, $source, 15, 15, 0, 0, imagesx($source), imagesy($source));
            imagedestroy($source);
            $source = $padded;
        }
        $path = $this->directory.'/query.jpg';
        imagejpeg($source, $path, 85);
        imagedestroy($source);

        return $path;
    }

    public static function photographs(): array
    {
        $cases = [];
        foreach ([1, 2, 3, 4, 5, 6, 7] as $product) {
            foreach (['original', 'lighting', 'background'] as $variation) {
                $cases['photo '.$product.' '.$variation] = [$product, $variation];
            }
        }

        return $cases;
    }

    #[DataProvider('photographs')]
    public function test_real_photographs_keep_the_matching_product_first_without_cross_type_filler(int $fixture, string $variation): void
    {
        $service = $this->catalog();
        $results = $service->search($this->queryPhoto($fixture, $variation))['products'];
        $expected = $this->products[$fixture];
        $this->assertSame($expected->id, $results->first()?->id);
        $this->assertSame([$expected->category_id], $results->pluck('category_id')->unique()->values()->all());
        $this->assertSame($results->count(), $results->pluck('id')->unique()->count());
        $this->assertLessThanOrEqual(3, $results->count());
    }

    public function test_category_is_explicit_buyer_evidence_and_does_not_fill_with_unrelated_items(): void
    {
        $service = $this->catalog();
        $book = $this->products[2];
        $path = $this->queryPhoto(2, 'background');
        $this->postJson(route('buyer.visual-search.search'), [
            'image' => new UploadedFile($path, 'query.jpg', 'image/jpeg', null, true),
            'category_id' => $book->category_id,
        ])->assertOk()->assertJsonPath('count', 1);
        $this->assertCount(0, $service->search($path, $this->products[5]->category_id)['products']);
        $this->postJson(route('buyer.visual-search.search'), [
            'image' => new UploadedFile($path, 'query.jpg', 'image/jpeg', null, true),
            'category_id' => 99999999,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_featureless_same_color_upload_has_no_reliable_shape_evidence(): void
    {
        $service = $this->catalog();
        $flat = imagecreatetruecolor(96, 96);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 245, 245, 245));
        $path = $this->directory.'/flat.png';
        imagepng($flat, $path);
        imagedestroy($flat);
        $this->assertCount(0, $service->search($path)['products']);
        $this->postJson(route('buyer.visual-search.search'), [
            'image' => new UploadedFile($path, 'flat.png', 'image/png', null, true),
        ])->assertOk()->assertJsonPath('count', 0)->assertSee('No catalog photos were similar enough');
    }

    public function test_relevant_listings_keep_similarity_order_across_categories_without_duplicate_cards(): void
    {
        $category = Category::create(['label' => 'Books', 'icon' => 'book']);
        $related = Category::create(['label' => 'Collectibles', 'icon' => 'package']);
        $weaker = Product::factory()->create(['category_id' => $related->id, 'status' => 'active', 'stock_quantity' => 10]);
        $closest = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'stock_quantity' => 10]);
        $padded = $this->queryPhoto(2, 'background');
        Storage::disk('public')->put('products/padded.jpg', File::get($padded));
        Storage::disk('public')->put('products/closest.jpg', File::get(base_path('tests/Fixtures/visual-search/catalog-2-0.jpg')));
        $weaker->images()->create(['path' => 'products/padded.jpg']);
        $closest->images()->createMany([['path' => 'products/closest.jpg'], ['path' => 'products/closest.jpg']]);
        $service = app(VisualSearchService::class);
        $service->rebuild();
        $this->assertSame([$closest->id, $weaker->id], $service->search($this->queryPhoto(2, 'original'))['products']->modelKeys());
    }

    public function test_real_jpeg_at_exactly_five_megabytes_is_accepted(): void
    {
        $this->catalog();
        $bytes = File::get(base_path('tests/Fixtures/visual-search/catalog-2-0.jpg'));
        $upload = UploadedFile::fake()->createWithContent('book.jpg', str_pad($bytes, 5 * 1024 * 1024, ' '));
        $this->postJson(route('buyer.visual-search.search'), ['image' => $upload])->assertOk();
    }

    public function test_no_semantic_model_is_required_and_no_semantic_claim_is_made(): void
    {
        $this->catalog();
        $path = $this->queryPhoto(7, 'original');
        $response = $this->postJson(route('buyer.visual-search.search'), [
            'image' => new UploadedFile($path, 'dress.jpg', 'image/jpeg', null, true),
        ])->assertOk();
        $this->assertStringContainsString('Similar image structure, colors and layout', $response->json('html'));
        $this->assertStringNotContainsString('Visually similar products', $response->json('html'));
        $this->assertStringNotContainsString('similarity%', $response->json('html'));
    }
}
