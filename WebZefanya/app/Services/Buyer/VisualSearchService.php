<?php

namespace App\Services\Buyer;

use App\Exceptions\VisualSearchUnavailable;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductImage;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use JsonException;

class VisualSearchService
{
    public function __construct(private VisualImageFeatures $features, private Filesystem $files, private VisualCatalogIndex $index, private VisualSemanticRecognition $semantic) {}

    private function images(): Builder
    {
        return ProductImage::query()->whereHas('product', fn (Builder $query) => $query->discoverable());
    }

    /** Only catalog-controlled, existing files beneath the local public disk may be read. */
    public function localImage(string $relative): ?string
    {
        if (config('filesystems.disks.public.driver') !== 'local' || $relative === ''
            || str_contains($relative, '..') || str_contains($relative, ':') || str_starts_with($relative, '/')
            || str_contains($relative, "\0") || str_contains($relative, '\\')) {
            return null;
        }
        $root = realpath(Storage::disk('public')->path(''));
        $path = realpath(Storage::disk('public')->path($relative));
        if ($root === false || $path === false || ! is_file($path)
            || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    private function fingerprint(string $path): ?string
    {
        clearstatcache(true, $path);
        $modified = @filemtime($path);
        $size = @filesize($path);
        if ($modified === false || $size === false) {
            return null;
        }

        return hash('sha256', $path.'|'.$modified.'|'.$size);
    }

    /** @return array{version: string, images: array, built_at?: string} */
    private function readIndex(bool $required = true): array
    {
        $path = config('visual-search.index_path');
        try {
            if (! is_file($path) || filesize($path) > config('visual-search.max_index_bytes')) {
                throw new JsonException;
            }
            $index = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
            if (($index['version'] ?? null) !== VisualImageFeatures::VERSION || ! is_array($index['images'] ?? null)) {
                throw new JsonException;
            }
            foreach ($index['images'] as $entry) {
                if (! is_array($entry) || ! is_string($entry['path'] ?? null)
                    || ! is_string($entry['fingerprint'] ?? null) || ! is_numeric($entry['product_id'] ?? null)
                    || ! is_array($entry['features'] ?? null)) {
                    throw new JsonException;
                }
                foreach (['color', 'center', 'layout', 'structure'] as $component) {
                    $values = $entry['features'][$component] ?? null;
                    if (! is_array($values) || count($values) < 1
                        || count($values) > match ($component) {
                            'layout' => 48, 'structure' => 128, default => 512
                        }
                        || (in_array($component, ['layout', 'structure'], true) && count($values) !== ($component === 'layout' ? 48 : 128))) {
                        throw new JsonException;
                    }
                    foreach ($values as $key => $value) {
                        if (! is_int($key) || $key < 0 || $key >= match ($component) {
                            'layout' => 48, 'structure' => 128, default => 512
                        }
                            || ! is_numeric($value) || $value < 0 || $value > 1) {
                            throw new JsonException;
                        }
                    }
                }
            }

            return $index;
        } catch (JsonException|FileNotFoundException $exception) {
            if ($required) {
                throw new VisualSearchUnavailable('Image search is being prepared. Please try again later.');
            }

            return ['version' => VisualImageFeatures::VERSION, 'images' => []];
        }
    }

    /** Bounded, resumable background indexing. Previously published entries remain searchable. */
    public function rebuild(int $batch = 200, int $maxBatches = 0, bool $force = false): array
    {
        $this->features->ensureAvailable();
        if (config('filesystems.disks.public.driver') !== 'local') {
            throw new VisualSearchUnavailable('The image index requires a local public image disk.');
        }
        $path = config('visual-search.index_path');
        $this->files->ensureDirectoryExists(dirname($path));
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new VisualSearchUnavailable('Another image-index build is already running.');
        }
        $started = microtime(true);
        try {
            $eligible = $this->images()->count();
            if ($eligible > config('visual-search.max_catalog_images')) {
                throw new VisualSearchUnavailable('Catalog exceeds the configured image limit; the previous index was preserved.');
            }
            $db = $this->index->database(true);
            if ($force) {
                $this->index->setCursor(0);
            }
            $stats = ['indexed' => 0, 'reused' => 0, 'deduplicated' => 0, 'skipped' => 0, 'removed' => 0];
            $batch = max(1, min(1000, $batch));
            $pending = $this->index->pending($batch);
            $pendingImages = $this->images()->whereIn('id', array_keys($pending))->get()->keyBy('id');
            foreach ($pending as $id => $token) {
                $this->indexImage($id, $stats, $force, $pendingImages->get($id));
                $this->index->acknowledge($id, $token);
            }
            $batches = 0;
            do {
                $rows = ProductImage::query()->where('id', '>', $this->index->cursor())->orderBy('id')->limit($batch)->pluck('id');
                if ($rows->isEmpty()) {
                    $this->pruneMissingImages();
                    $this->index->setCursor(0);
                    break;
                }
                clearstatcache(true, $this->index->path());
                if (filesize($this->index->path()) > config('visual-search.max_cache_bytes')) {
                    throw new VisualSearchUnavailable('The derived image cache reached its configured disk limit.');
                }
                $db->beginTransaction();
                try {
                    $eligibleImages = $this->images()->whereIn('id', $rows)->get()->keyBy('id');
                    foreach ($rows as $id) {
                        $this->indexImage((int) $id, $stats, $force, $eligibleImages->get($id));
                    }
                    $this->index->setCursor((int) $rows->last());
                    $db->commit();
                } catch (\Throwable $exception) {
                    $db->rollBack();
                    throw $exception;
                }
                $batches++;
            } while ($maxBatches === 0 || $batches < $maxBatches);

            if ($this->semantic->enabled()) {
                $this->indexSemantic();
            }

            return $stats + ['eligible' => $eligible, 'cached' => (int) $db->query('SELECT COUNT(*) FROM images')->fetchColumn(),
                'cursor' => $this->index->cursor(), 'seconds' => round(microtime(true) - $started, 3)];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function indexImage(int $id, array &$stats, bool $force, ?ProductImage $image): void
    {
        $local = $image ? $this->localImage((string) $image->path) : null;
        if ($image === null || $local === null) {
            $this->index->forget($id);
            $stats[$image === null ? 'removed' : 'skipped']++;

            return;
        }
        $fingerprint = $this->fingerprint($local);
        if ($fingerprint === null) {
            $this->index->forget($id);
            $stats['skipped']++;

            return;
        }
        $cached = $this->index->row($id);
        if (! $force && $cached && $cached['path'] === $image->path
            && (int) $cached['product_id'] === (int) $image->product_id
            && $cached['fingerprint'] === $fingerprint && $cached['version'] === VisualImageFeatures::VERSION
            && $this->decodeFeatures($cached['data']) !== null) {
            $stats['reused']++;

            return;
        }
        $hash = $this->index->hashForFingerprint($fingerprint) ?? @hash_file('sha256', $local);
        if ($hash === false) {
            $stats['skipped']++;

            return;
        }
        $data = $force ? null : $this->index->descriptor($hash);
        if ($data === null || $this->decodeFeatures($data) === null) {
            try {
                $data = json_encode($this->features->describe($local), JSON_THROW_ON_ERROR);
            } catch (ValidationException) {
                $this->index->forget($id);
                $stats['skipped']++;

                return;
            }
        } else {
            $stats['deduplicated']++;
        }
        // Do not publish features for a file that changed during decoding.
        if ($fingerprint !== $this->fingerprint($local)) {
            $stats['skipped']++;

            return;
        }
        $this->index->put($id, (int) $image->product_id, (string) $image->path, $fingerprint, $hash, $data);
        $stats['indexed']++;
    }

    private function pruneMissingImages(): void
    {
        $db = $this->index->database();
        $after = 0;
        do {
            $statement = $db->prepare('SELECT id FROM images WHERE id>? ORDER BY id LIMIT 200');
            $statement->execute([$after]);
            $ids = $statement->fetchAll(\PDO::FETCH_COLUMN);
            $statement->closeCursor();
            $present = $this->images()->whereIn('id', $ids)->pluck('id')->all();
            foreach (array_diff($ids, $present) as $id) {
                $this->index->forget((int) $id);
            }
            $after = $ids ? (int) end($ids) : 0;
        } while (count($ids) === 200);
        $db->exec('DELETE FROM features WHERE NOT EXISTS (SELECT 1 FROM images WHERE images.hash=features.hash AND images.version=features.version)');
        $db->exec('DELETE FROM semantic_features WHERE NOT EXISTS (SELECT 1 FROM images WHERE images.hash=semantic_features.hash)');
    }

    private function indexSemantic(): void
    {
        $db = $this->index->database();
        $statement = $db->prepare('SELECT i.* FROM images i LEFT JOIN semantic_features s ON s.hash=i.hash AND s.version=? WHERE s.hash IS NULL GROUP BY i.hash ORDER BY i.id LIMIT ?');
        $statement->bindValue(1, config('visual-search.semantic.version'));
        $statement->bindValue(2, max(1, min(32, (int) config('visual-search.semantic.index_batch'))), \PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $statement->closeCursor();
        foreach ($rows as $row) {
            $local = $this->localImage($row['path']);
            if ($local === null || $row['fingerprint'] !== $this->fingerprint($local)) {
                continue;
            }
            $descriptor = $this->semantic->describe($local);
            if ($descriptor === null) {
                break; // An unavailable/busy worker must not multiply request timeouts across a batch.
            }
            if ($row['fingerprint'] === $this->fingerprint($local)) {
                $this->index->putSemantic($row['hash'], $descriptor);
            }
        }
    }

    private function decodeFeatures(string $json): ?array
    {
        try {
            $features = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            foreach (['color', 'center', 'layout', 'structure'] as $component) {
                $values = $features[$component] ?? null;
                if (! is_array($values) || count($values) < 1 || count($values) > match ($component) {
                    'layout' => 48, 'structure' => 128, default => 512
                }
                    || (in_array($component, ['layout', 'structure'], true) && count($values) !== ($component === 'layout' ? 48 : 128))) {
                    return null;
                }
                foreach ($values as $key => $value) {
                    if (! is_int($key) || $key < 0 || $key >= match ($component) {
                        'layout' => 48, 'structure' => 128, default => 512
                    }
                        || ! is_numeric($value) || $value < 0 || $value > 1) {
                        return null;
                    }
                }
            }

            return $features;
        } catch (JsonException) {
            return null;
        }
    }

    /** @return array{products: Collection, partial: bool} */
    public function search(string $uploadedPath, ?int $categoryId = null): array
    {
        $query = $this->features->describe($uploadedPath);
        if (! $this->images()->exists()) {
            return ['products' => collect(), 'partial' => false];
        }
        $incremental = $this->index->exists();
        $index = $this->readIndex(! $incremental)['images'];
        $recognition = $this->semantic->describe($uploadedPath);
        $recognized = $this->semantic->confident($recognition);
        $images = $this->images()->when($categoryId !== null, fn ($query) => $query->whereHas('product', fn ($product) => $product->where('category_id', $categoryId)))
            ->select(['id', 'product_id', 'path'])->lazyById(200);
        $scores = [];
        $categoryScores = [];
        $usable = $missing = 0;
        foreach ($images as $image) {
            $cached = $incremental ? $this->index->row((int) $image->id) : null;
            $fromCache = $cached !== null;
            $cached ??= $index[$image->id] ?? null;
            $local = $this->localImage((string) $image->path);
            if ($cached === null || $local === null || $cached['path'] !== $image->path
                || (int) $cached['product_id'] !== (int) $image->product_id
                || ($fromCache && $cached['version'] !== VisualImageFeatures::VERSION)
                || $cached['fingerprint'] !== $this->fingerprint($local)) {
                $missing++;

                continue;
            }
            $descriptor = $fromCache ? $this->decodeFeatures($cached['data']) : $cached['features'];
            if ($descriptor === null) {
                $missing++;

                continue;
            }
            $usable++;
            $visualScore = $this->features->similarity($query, $descriptor);
            if ($recognition !== null && ! $recognized && $visualScore < 0.95) {
                continue; // An uncertain semantic result permits only strong photo evidence.
            }
            $catalogSemantic = $recognized && isset($cached['hash']) ? $this->index->semantic($cached['hash']) : null;
            $catalogConfident = $this->semantic->confident($catalogSemantic);
            if ($recognized && $catalogConfident && $this->semantic->compatible($recognition, $catalogSemantic)) {
                $similarity = $this->semantic->similarity($recognition, $catalogSemantic);
                if ($similarity >= config('visual-search.semantic.minimum_image_similarity')) {
                    $typeBonus = $recognition['object'] === $catalogSemantic['object'] ? 0.05 : 0;
                    $categoryScores[$image->product_id] = max($categoryScores[$image->product_id] ?? 0, 0.85 * $similarity + 0.15 * $visualScore + $typeBonus);
                }
            }
            if ($recognized && $catalogSemantic !== null && $this->semantic->valid($catalogSemantic)
                && ! $this->semantic->compatible($recognition, $catalogSemantic)) {
                continue;
            }
            // Color alone cannot qualify an object. Require independent structural evidence.
            if ($this->features->structuralSimilarity($query, $descriptor) < config('visual-search.minimum_structure_similarity')) {
                continue;
            }
            $score = $visualScore;
            $scores[$image->product_id] = max($scores[$image->product_id] ?? 0, $score);
        }
        if ($usable === 0 && $missing > 0) {
            throw new VisualSearchUnavailable('Catalog photos are being updated. Please try image search again later.');
        }
        arsort($scores, SORT_NUMERIC);
        $threshold = max((float) config('visual-search.minimum_similarity'), ($scores ? reset($scores) : 0) - (float) config('visual-search.maximum_score_gap'));
        $ids = array_keys(array_filter($scores, fn ($score) => $score >= $threshold));
        // Recheck live visibility after ranking; stale indexes never confer access.
        $products = Product::query()->discoverable()->withBuyerCardData()->with(['images', 'seller', 'category'])
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->whereIn('id', array_slice($ids, 0, 40))->get()->sortBy(fn ($product) => array_search($product->id, $ids, true))
            ->take(max(1, min(20, (int) config('visual-search.results_limit'))))->values();

        $recommendations = collect();
        if ($recognized) {
            arsort($categoryScores, SORT_NUMERIC);
            $categoryIds = array_values(array_diff(array_keys($categoryScores), $products->modelKeys()));
            $recommendations = Product::query()->discoverable()->withBuyerCardData()->with(['images', 'seller', 'category'])
                ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
                ->whereIn('id', array_slice($categoryIds, 0, 40))->get()
                ->sortBy(fn ($product) => array_search($product->id, $categoryIds, true))
                ->take(max(0, min(16, (int) config('visual-search.results_limit')) - $products->count()))->values();
        }

        return ['products' => $products, 'partial' => $missing > 0, 'recommendations' => $recommendations,
            'recognizedCategory' => $recognized ? $this->semantic->label($recognition) : null,
            'recognitionStatus' => $recognized ? 'recognized' : ($recognition === null ? 'unavailable' : 'uncertain')];
    }
}
