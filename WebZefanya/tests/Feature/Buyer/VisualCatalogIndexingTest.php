<?php

namespace Tests\Feature\Buyer;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductImage;
use App\Services\Buyer\VisualCatalogIndex;
use App\Services\Buyer\VisualImageFeatures;
use App\Services\Buyer\VisualSearchService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisualCatalogIndexingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $directory;

    private string $bytes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->directory = storage_path('framework/testing/visual-index-'.Str::uuid());
        Storage::set('public', Storage::build(['driver' => 'local', 'root' => $this->directory.'/images']));
        config(['visual-search.enabled' => true, 'visual-search.index_path' => $this->directory.'/catalog.json']);
        $image = imagecreatetruecolor(96, 96);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 65, 140));
        imagerectangle($image, 18, 12, 76, 84, imagecolorallocate($image, 25, 25, 25));
        imageline($image, 18, 12, 76, 84, imagecolorallocate($image, 25, 25, 25));
        ob_start();
        imagepng($image);
        $this->bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('public')->put('products/a.png', $this->bytes);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && str_starts_with($this->directory, storage_path('framework/testing/visual-index-'))) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function product(): Product
    {
        return Product::factory()->create(['status' => 'active', 'stock_quantity' => 10]);
    }

    public function test_events_wait_for_commit_coalesce_and_do_not_survive_rollback(): void
    {
        $product = $this->product();
        $index = app(VisualCatalogIndex::class);
        DB::beginTransaction();
        $image = $product->images()->create(['path' => 'products/a.png']);
        $this->assertSame([], $index->pending(20));
        DB::commit();
        $this->assertArrayHasKey($image->id, $index->pending(20));
        Storage::disk('public')->put('products/b.png', $this->bytes);
        $image->update(['path' => 'products/b.png']);
        $this->assertCount(1, $index->pending(20));
        DB::beginTransaction();
        $rolledBack = $product->images()->create(['path' => 'products/a.png']);
        DB::rollBack();
        $this->assertArrayNotHasKey($rolledBack->id, $index->pending(20));
        $old = $index->pending(20)[$image->id];
        $index->mark($image->id);
        $index->acknowledge($image->id, $old);
        $this->assertArrayHasKey($image->id, $index->pending(20));
        $this->artisan('visual-search:index')->assertSuccessful();
        $this->assertSame([], $index->pending(20));
        $this->assertSame('products/b.png', $index->row($image->id)['path']);
    }

    public function test_resume_recovers_bulk_changes_removals_and_approval_without_events(): void
    {
        $product = $this->product();
        $pending = Product::factory()->create(['status' => 'pending', 'stock_quantity' => 10]);
        $rows = [];
        for ($i = 0; $i < 7; $i++) {
            $rows[] = ['product_id' => $product->id, 'path' => 'products/a.png'];
        }
        $rows[] = ['product_id' => $pending->id, 'path' => 'products/a.png'];
        ProductImage::insert($rows);
        $this->artisan('visual-search:index --batch=2 --max-batches=1')->assertSuccessful();
        $index = app(VisualCatalogIndex::class);
        $this->assertSame(2, (int) $index->database()->query('SELECT COUNT(*) FROM images')->fetchColumn());
        $this->assertGreaterThan(0, $index->cursor());
        $this->artisan('visual-search:index --batch=2')->assertSuccessful();
        $this->assertSame(7, (int) $index->database()->query('SELECT COUNT(*) FROM images')->fetchColumn());
        $this->assertSame(0, $index->cursor());
        $pending->update(['status' => 'active']);
        $pending->variants()->update(['is_available' => true]);
        $this->artisan('visual-search:index')->assertSuccessful();
        $this->assertSame(8, (int) $index->database()->query('SELECT COUNT(*) FROM images')->fetchColumn());
        $removed = $product->images()->first();
        ProductImage::whereKey($removed->id)->delete();
        $this->artisan('visual-search:index')->assertSuccessful();
        $this->assertNull($index->row($removed->id));
        $product->update(['status' => 'inactive']);
        $this->artisan('visual-search:index')->assertSuccessful();
        $this->assertSame(1, (int) $index->database()->query('SELECT COUNT(*) FROM images')->fetchColumn());
    }

    public function test_identical_files_share_features_and_deletions_are_scheduled(): void
    {
        $product = $this->product();
        Storage::disk('public')->put('products/duplicate.png', $this->bytes);
        $a = $product->images()->create(['path' => 'products/a.png']);
        $b = $product->images()->create(['path' => 'products/duplicate.png']);
        $this->artisan('visual-search:index')->assertSuccessful();
        $index = app(VisualCatalogIndex::class);
        $this->assertSame(2, (int) $index->database()->query('SELECT COUNT(*) FROM images')->fetchColumn());
        $this->assertSame(1, (int) $index->database()->query('SELECT COUNT(*) FROM features')->fetchColumn());
        $a->delete();
        $this->assertArrayHasKey($a->id, $index->pending(10));
        $this->artisan('visual-search:index')->assertSuccessful();
        $this->assertNull($index->row($a->id));
        $this->assertNotNull($index->row($b->id));
        $before = $index->row($b->id);
        $this->artisan('visual-search:index --rebuild')->assertSuccessful();
        $this->assertSame($before, $index->row($b->id));
    }

    public function test_scheduler_registers_bounded_background_reconciliation(): void
    {
        $events = app(Schedule::class)->events();
        $event = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'visual-search:index'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        $this->assertStringContainsString('--max-batches=5', $event->command);
        $this->artisan('visual-search:index --batch=0')->assertFailed();
        $this->artisan('visual-search:index --max-batches=-1')->assertFailed();
    }

    public function test_legacy_index_remains_searchable_while_the_incremental_cache_is_incomplete(): void
    {
        $product = $this->product();
        $image = $product->images()->create(['path' => 'products/a.png']);
        $this->artisan('visual-search:index')->assertSuccessful();
        $index = app(VisualCatalogIndex::class);
        $row = $index->row($image->id);
        file_put_contents(config('visual-search.index_path'), json_encode(['version' => $row['version'], 'images' => [$image->id => [
            'path' => $row['path'], 'product_id' => $row['product_id'], 'fingerprint' => $row['fingerprint'],
            'features' => json_decode($row['data'], true),
        ]]]));
        $index->forget($image->id);
        $upload = UploadedFile::fake()->createWithContent('query.png', $this->bytes);
        $this->postJson(route('buyer.visual-search.search'), ['image' => $upload])->assertOk()->assertJsonPath('count', 1);
    }

    public function test_old_feature_versions_are_excluded_and_repaired(): void
    {
        $product = $this->product();
        $image = $product->images()->create(['path' => 'products/a.png']);
        $this->artisan('visual-search:index')->assertSuccessful();
        $index = app(VisualCatalogIndex::class);
        $index->database()->exec("UPDATE images SET version='retired-test-version'");
        $upload = UploadedFile::fake()->createWithContent('query.png', $this->bytes);
        $this->postJson(route('buyer.visual-search.search'), ['image' => $upload])->assertStatus(503);
        $this->artisan('visual-search:index')->assertSuccessful();
        $this->assertSame(VisualImageFeatures::VERSION, $index->row($image->id)['version']);
    }

    public static function catalogSizes(): array
    {
        return [[100], [1000], [10000]];
    }

    #[DataProvider('catalogSizes')]
    public function test_synthetic_catalog_scaling(int $size): void
    {
        $product = $this->product();
        $paths = [];
        for ($i = 0; $i < 10; $i++) {
            $image = imagecreatetruecolor(96, 96);
            imagefill($image, 0, 0, imagecolorallocate($image, 225 + $i, 60 + $i, 130 + $i));
            ob_start();
            imagepng($image);
            $paths[] = $path = 'products/bench-'.$i.'.png';
            Storage::disk('public')->put($path, ob_get_clean());
            imagedestroy($image);
        }
        for ($offset = 0; $offset < $size; $offset += 200) {
            $rows = [];
            for ($i = $offset; $i < min($offset + 200, $size); $i++) {
                $rows[] = ['product_id' => $product->id, 'path' => $paths[$i % 10]];
            }
            ProductImage::insert($rows);
        }
        $service = app(VisualSearchService::class);
        memory_reset_peak_usage();
        $cold = $service->rebuild();
        $warm = $service->rebuild();
        $upload = UploadedFile::fake()->createWithContent('query.png', $this->bytes);
        $started = microtime(true);
        $result = $service->search($upload->getPathname());
        $searchSeconds = round(microtime(true) - $started, 3);
        $this->assertSame($size, $cold['cached']);
        $this->assertSame($size, $warm['reused']);
        // The benchmark catalog contains only flat swatches: no structural match is credible.
        $this->assertCount(0, $result['products']);
        fwrite(STDERR, '\nVISUAL_INDEX_BENCHMARK '.json_encode(['records' => $size, 'physical_photos' => 10,
            'cold_seconds' => $cold['seconds'], 'warm_seconds' => $warm['seconds'], 'search_seconds' => $searchSeconds,
            'php_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1)]).'\n');
    }
}
