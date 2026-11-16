<?php

namespace Tests\Feature\Buyer;

use App\Models\Catalog\Product;
use App\Models\Shared\Category;
use App\Services\Buyer\VisualSearchService;
use App\Services\Buyer\VisualSemanticRecognition;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Recorded real-model descriptors test retrieval deterministically; live inference is verified separately. */
class ObjectAwareVisualSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $directory;

    private array $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->directory = storage_path('framework/testing/object-aware-'.Str::uuid());
        Storage::set('public', Storage::build(['driver' => 'local', 'root' => $this->directory.'/images']));
        config(['visual-search.index_path' => $this->directory.'/catalog.json', 'visual-search.semantic.enabled' => true]);
        $this->records = json_decode(File::get(base_path('tests/Fixtures/object-aware/recorded-descriptors.json')), true);
        $records = $this->records;
        $provider = new class($records) extends VisualSemanticRecognition
        {
            public function __construct(private array $records) {}

            public function enabled(): bool
            {
                return (bool) config('visual-search.semantic.enabled');
            }

            public function describe(string $path): ?array
            {
                return $this->enabled() ? ($this->records[hash_file('sha256', $path)] ?? null) : null;
            }
        };
        app()->instance(VisualSemanticRecognition::class, $provider);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with($this->directory, storage_path('framework/testing/object-aware-'))) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function listing(string $category, string $fixture, ?string $name = null): Product
    {
        $category = Category::firstOrCreate(['label' => $category], ['icon' => 'package']);
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => $name ?? Str::uuid(), 'status' => 'active', 'stock_quantity' => 10]);
        $path = 'products/'.$product->id.'/photo.'.pathinfo($fixture, PATHINFO_EXTENSION);
        Storage::disk('public')->put($path, File::get(base_path('tests/Fixtures/'.$fixture)));
        $product->images()->create(['path' => $path]);

        return $product;
    }

    private function basicCatalog(): array
    {
        return [
            $this->listing('Books', 'visual-search/catalog-1-0.jpg'),
            $this->listing('Books', 'visual-search/catalog-2-0.jpg'),
            $this->listing('Books', 'visual-search/catalog-3-0.jpg'),
            $this->listing('Apparel', 'visual-search/catalog-7-0.jpg'),
        ];
    }

    private function upload(string $fixture): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(basename($fixture), File::get(base_path('tests/Fixtures/'.$fixture)));
    }

    public static function bookQueries(): array
    {
        return [['object-aware/book.jpg'], ['object-aware/stack-books.jpg'], ['visual-search-discovery/stacked-books-illustration.png']];
    }

    #[DataProvider('bookQueries')]
    public function test_different_covers_and_stacks_recommend_real_books_without_clothing(string $fixture): void
    {
        $catalog = $this->basicCatalog();
        app(VisualSearchService::class)->rebuild();
        $response = $this->postJson(route('buyer.visual-search.search'), ['image' => $this->upload($fixture)])->assertOk();
        $html = $response->json('html');
        $this->assertGreaterThan(0, $response->json('category_count'));
        $this->assertStringContainsString('More products in Books', $html);
        $this->assertStringContainsString('not confirmed visual or exact matches', $html);
        $this->assertStringNotContainsString('data-product-id="'.$catalog[3]->id.'"', $html);
        Http::assertNothingSent();
    }

    public function test_exact_photo_stays_first_and_is_not_duplicated_as_a_category_recommendation(): void
    {
        $catalog = $this->basicCatalog();
        $service = app(VisualSearchService::class);
        $service->rebuild();
        $result = $service->search(base_path('tests/Fixtures/visual-search/catalog-3-0.jpg'));
        $this->assertSame($catalog[2]->id, $result['products']->first()?->id);
        $this->assertNotContains($catalog[2]->id, $result['recommendations']->modelKeys());
    }

    public static function supportedTypes(): array
    {
        return [
            'dress' => ['Apparel', 'visual-search/catalog-7-0.jpg', 'object-aware/dress.jpg', 'Dresses'],
            'shoes' => ['Footwear', 'visual-search-discovery/shoe-illustration.png', 'object-aware/shoes.jpg', 'Footwear'],
            'bag' => ['Bags', 'visual-search-discovery/bag-illustration.png', 'object-aware/bag.jpg', 'Bags'],
            'electronics' => ['Electronics', 'object-aware/phone.jpg', 'object-aware/phone.jpg', 'Phones'],
        ];
    }

    #[DataProvider('supportedTypes')]
    public function test_recognition_finds_supported_types_without_unrelated_books(string $category, string $catalogImage, string $query, string $label): void
    {
        $match = $this->listing($category, $catalogImage);
        $book = $this->listing('Books', 'visual-search/catalog-3-0.jpg');
        app(VisualSearchService::class)->rebuild();
        $result = app(VisualSearchService::class)->search(base_path('tests/Fixtures/'.$query));
        $ids = $result['products']->merge($result['recommendations'])->modelKeys();
        $this->assertContains($match->id, $ids);
        $this->assertNotContains($book->id, $ids);
        $this->assertSame($label, $result['recognizedCategory']);
    }

    public static function unknowns(): array
    {
        return [['white.png'], ['black.png'], ['gray.png'], ['noise.png'], ['abstract.png']];
    }

    #[DataProvider('unknowns')]
    public function test_unknown_or_ambiguous_inputs_do_not_generate_category_recommendations(string $fixture): void
    {
        $this->basicCatalog();
        app(VisualSearchService::class)->rebuild();
        $result = app(VisualSearchService::class)->search(base_path('tests/Fixtures/object-aware/'.$fixture));
        $this->assertNull($result['recognizedCategory']);
        $this->assertCount(0, $result['recommendations']);
    }

    public function test_manual_category_remains_authoritative_and_hidden_products_are_excluded(): void
    {
        $catalog = $this->basicCatalog();
        $catalog[0]->update(['status' => 'pending']);
        $catalog[1]->seller->forceFill(['status' => 'suspended'])->save();
        $service = app(VisualSearchService::class);
        $service->rebuild();
        $path = base_path('tests/Fixtures/object-aware/stack-books.jpg');
        $result = $service->search($path);
        $this->assertSame([$catalog[2]->id], $result['recommendations']->modelKeys());
        $this->assertCount(0, $service->search($path, $catalog[3]->category_id)['recommendations']);
        $catalog[2]->variants()->update(['stock_quantity' => 0]);
        $this->assertCount(0, $service->search($path)['recommendations']);
    }

    public function test_failure_or_timeout_preserves_exact_visual_fallback(): void
    {
        $catalog = $this->basicCatalog();
        app(VisualSearchService::class)->rebuild();
        $key = $this->directory.'/key';
        File::put($key, str_repeat('a', 64));
        config(['visual-search.semantic.token_path' => $key]);
        $client = new class extends VisualSemanticRecognition
        {
            public function enabled(): bool
            {
                return true;
            }
        };
        app()->instance(VisualSemanticRecognition::class, $client);
        app()->forgetInstance(VisualSearchService::class);
        Http::fake(fn () => throw new ConnectionException('Simulated timeout'));
        $result = app(VisualSearchService::class)->search(base_path('tests/Fixtures/visual-search/catalog-3-0.jpg'));
        $this->assertSame($catalog[2]->id, $result['products']->first()?->id);
        $this->assertCount(0, $result['recommendations']);
    }

    public function test_invalid_version_dimensions_norm_or_non_finite_scores_are_rejected(): void
    {
        $client = app(VisualSemanticRecognition::class);
        $valid = reset($this->records);
        $this->assertTrue($client->valid($valid));
        foreach (['version' => 'retired', 'embedding' => [1, 0], 'score' => INF, 'family' => 'invented'] as $key => $value) {
            $invalid = $valid;
            $invalid[$key] = $value;
            $this->assertFalse($client->valid($invalid));
        }
        $valid['embedding'] = array_fill(0, 512, 0);
        $this->assertFalse($client->valid($valid));
    }

    public function test_a_phone_does_not_recommend_unrelated_gaming_items_in_the_same_broad_family(): void
    {
        $this->listing('Electronics', 'visual-search/catalog-4-1.jpg');
        app(VisualSearchService::class)->rebuild();
        $result = app(VisualSearchService::class)->search(base_path('tests/Fixtures/object-aware/phone.jpg'));
        $this->assertSame('Phones', $result['recognizedCategory']);
        $this->assertCount(0, $result['recommendations']);
    }

    public function test_unknown_texture_does_not_leak_a_structural_lookalike(): void
    {
        $this->listing('Electronics', 'visual-search/catalog-4-0.jpg');
        app(VisualSearchService::class)->rebuild();
        $result = app(VisualSearchService::class)->search(base_path('tests/Fixtures/object-aware/noise.png'));
        $this->assertCount(0, $result['products']);
        $this->assertCount(0, $result['recommendations']);
    }

    public function test_local_http_client_reads_the_complete_embedding_body(): void
    {
        $key = $this->directory.'/key';
        File::ensureDirectoryExists($this->directory);
        File::put($key, str_repeat('a', 64));
        config(['visual-search.semantic.token_path' => $key]);
        $record = reset($this->records);
        Http::fake(['http://127.0.0.1:8766/embed' => Http::response($record)]);
        $client = new class extends VisualSemanticRecognition
        {
            public function enabled(): bool
            {
                return true;
            }
        };
        $this->assertTrue($client->valid($client->describe(base_path('tests/Fixtures/visual-search/catalog-3-0.jpg'))));
    }
}
