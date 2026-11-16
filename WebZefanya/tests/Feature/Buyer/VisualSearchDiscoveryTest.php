<?php

namespace Tests\Feature\Buyer;

use App\Models\Catalog\Product;
use App\Models\Shared\Category;
use App\Services\Buyer\VisualSearchService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisualSearchDiscoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->directory = storage_path('framework/testing/visual-discovery-'.Str::uuid());
        Storage::set('public', Storage::build(['driver' => 'local', 'root' => $this->directory.'/images']));
        config(['visual-search.index_path' => $this->directory.'/catalog.json']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with($this->directory, storage_path('framework/testing/visual-discovery-'))) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function listing(string $label, string $fixture = 'visual-search/catalog-3-0.jpg'): Product
    {
        $category = Category::create(['label' => $label, 'icon' => 'package']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'stock_quantity' => 10]);
        $path = 'products/'.$product->id.'/photo.'.pathinfo($fixture, PATHINFO_EXTENSION);
        Storage::disk('public')->put($path, File::get(base_path('tests/Fixtures/'.$fixture)));
        $product->images()->create(['path' => $path]);

        return $product;
    }

    private function unknownUpload(): UploadedFile
    {
        $image = imagecreatetruecolor(64, 64);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 200, 200));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('unknown.png', $bytes);
    }

    public function test_book_illustration_abstains_and_offers_book_browsing_without_inventing_a_visual_match(): void
    {
        $book = $this->listing('Books and Media');
        app(VisualSearchService::class)->rebuild();
        $upload = UploadedFile::fake()->createWithContent('stack.png', File::get(base_path('tests/Fixtures/visual-search-discovery/stacked-books-illustration.png')));
        $response = $this->postJson(route('buyer.visual-search.search'), ['image' => $upload, 'category_id' => $book->category_id])
            ->assertOk()->assertJsonPath('count', 0);
        $html = $response->json('html');
        $this->assertStringContainsString('An illustration', $html);
        $this->assertStringContainsString('not matches identified from your image', $html);
        $this->assertStringContainsString(route('buyer.categories.show', $book->category_id), $html);
        $this->assertStringNotContainsString('data-product-id', $html);
        Http::assertNothingSent();
    }

    public function test_category_changes_only_offer_available_navigation_within_the_selected_category(): void
    {
        $books = $this->listing('Books');
        $clothes = $this->listing('Clothing', 'visual-search/catalog-7-0.jpg');
        app(VisualSearchService::class)->rebuild();
        foreach ([$books, $clothes] as $selected) {
            $other = $selected->is($books) ? $clothes : $books;
            $response = $this->postJson(route('buyer.visual-search.search'), ['image' => $this->unknownUpload(), 'category_id' => $selected->category_id])
                ->assertOk()->assertJsonPath('count', 0);
            $this->assertStringContainsString(route('buyer.categories.show', $selected->category_id), $response->json('html'));
            $this->assertStringNotContainsString(route('buyer.categories.show', $other->category_id).'"', $response->json('html'));
        }
    }

    public function test_no_category_is_inferred_and_labels_are_escaped(): void
    {
        $book = $this->listing('Books <script>alert(1)</script>');
        $clothes = $this->listing('Clothing', 'visual-search/catalog-7-0.jpg');
        app(VisualSearchService::class)->rebuild();
        $response = $this->postJson(route('buyer.visual-search.search'), ['image' => $this->unknownUpload()])->assertOk();
        $html = $response->json('html');
        $this->assertStringContainsString(route('buyer.categories.show', $book->category_id), $html);
        $this->assertStringContainsString(route('buyer.categories.show', $clothes->category_id), $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public static function hiddenStates(): array
    {
        return [['pending'], ['rejected'], ['inactive'], ['seller_suspended'], ['out_of_stock']];
    }

    #[DataProvider('hiddenStates')]
    public function test_hidden_or_unavailable_categories_are_not_advertised(string $state): void
    {
        $visible = $this->listing('Visible books');
        $hidden = $this->listing('Unavailable category', 'visual-search/catalog-7-0.jpg');
        if ($state === 'seller_suspended') {
            $hidden->seller->forceFill(['status' => 'suspended'])->save();
        } elseif ($state === 'out_of_stock') {
            $hidden->variants()->update(['stock_quantity' => 0]);
        } else {
            $hidden->update(['status' => $state]);
        }
        app(VisualSearchService::class)->rebuild();
        $response = $this->postJson(route('buyer.visual-search.search'), ['image' => $this->unknownUpload()])->assertOk();
        $this->assertStringContainsString(route('buyer.categories.show', $visible->category_id), $response->json('html'));
        $this->assertStringNotContainsString('Unavailable category', $response->json('html'));
    }

    public function test_empty_catalog_does_not_offer_empty_categories(): void
    {
        Category::create(['label' => 'No products', 'icon' => 'package']);
        $response = $this->postJson(route('buyer.visual-search.search'), ['image' => $this->unknownUpload()])
            ->assertOk()->assertJsonPath('count', 0);
        $this->assertStringNotContainsString('Browse categories after image search', $response->json('html'));
    }

    public static function exactPhotographs(): array
    {
        return [
            'book photograph' => ['Books', 'visual-search/catalog-3-0.jpg'],
            'clothing photograph' => ['Clothing', 'visual-search/catalog-7-0.jpg'],
            'shoe illustration - exact only' => ['Shoes', 'visual-search-discovery/shoe-illustration.png'],
            'bag illustration - exact only' => ['Bags', 'visual-search-discovery/bag-illustration.png'],
        ];
    }

    #[DataProvider('exactPhotographs')]
    public function test_exact_retrieval_remains_operational_without_any_semantic_service(string $label, string $fixture): void
    {
        $match = $this->listing($label, $fixture);
        $this->listing('Unrelated product', $fixture === 'visual-search/catalog-7-0.jpg' ? 'visual-search/catalog-3-0.jpg' : 'visual-search/catalog-7-0.jpg');
        app(VisualSearchService::class)->rebuild();
        $upload = UploadedFile::fake()->createWithContent(basename($fixture), File::get(base_path('tests/Fixtures/'.$fixture)));
        $response = $this->postJson(route('buyer.visual-search.search'), ['image' => $upload])->assertOk()->assertJsonPath('count', 1);
        $this->assertStringContainsString('data-product-id="'.$match->id.'"', $response->json('html'));
        $this->assertStringNotContainsString('Browse categories after image search', $response->json('html'));
        Http::assertNothingSent();
    }
}
