<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Seller\Seller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductPublishingService
{
    public function __construct(private VariantSyncService $variants) {}

    /** Save a listing and all its inventory/image associations as one operation. */
    public function save(Product $product, Seller $seller, array $data): Product
    {
        $stored = [];
        $removedPaths = [];
        try {
            $saved = DB::transaction(function () use ($product, $seller, $data, &$stored, &$removedPaths): Product {
                $withVariants = filter_var($data['variants_exist'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($product->exists) {
                    $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                    abort_if($product->seller_id !== $seller->id, 403);
                    $product->setRelation('variants', $product->variants()->orderBy('id')->lockForUpdate()->get());
                    if (! hash_equals($this->inventoryRevision($product), (string) ($data['inventory_revision'] ?? ''))) {
                        throw ValidationException::withMessages(['inventory_revision' => 'Inventory changed after this form was opened. Reload the product before saving so recent sales are preserved.']);
                    }
                }
                abort_if($product->exists && $product->seller_id !== $seller->id, 403);
                $product->fill([
                    'seller_id' => $seller->id,
                    'category_id' => $seller->line_of_business_id,
                    'name' => $data['name'],
                    'slug' => ! $product->exists || $data['name'] !== $product->name
                        ? Product::generateUniqueSlug($data['name']) : $product->slug,
                    'description' => $data['description'] ?? null,
                    'price' => $data['price'],
                    // Newly created inventory starts at zero; StockService writes its ledger.
                    'stock_quantity' => ! $product->exists ? 0 : $data['stock_quantity'],
                    'low_stock_threshold' => $data['low_stock_threshold'] ?? 5,
                    'status' => $data['status'],
                ]);
                $product->save();
                if (! $withVariants) {
                    $product->stock_quantity = (int) $data['stock_quantity'];
                }
                $this->variants->sync($product, $withVariants ? $data['options'] : [], $withVariants ? $data['variants'] : [], $withVariants);
                $this->generalImages($product, $data, $stored);
                $this->removeColorImages($product, $data['color_images'] ?? [], $removedPaths);
                $this->colorImages($product, $data['color_images'] ?? [], $stored, (bool) ($data['color_first'] ?? false));
                foreach ($data['variant_images'] ?? [] as $id => $files) {
                    if (! $product->variants()->whereKey($id)->exists()) {
                        continue;
                    }
                    foreach ($files as $file) {
                        $this->attachImage($product, $this->store($product, $file, $stored), (int) $id);
                    }
                }

                return $product->refresh();
            });
        } catch (Throwable $exception) {
            if ($stored !== []) {
                Storage::disk('public')->delete($stored);
            }
            throw $exception;
        }
        // Never remove physical files before the save transaction commits.
        DB::afterCommit(function () use ($removedPaths): void {
            foreach (array_unique($removedPaths) as $path) {
                if (! ProductImage::query()->where('path', $path)->exists()) {
                    Storage::disk('public')->delete($path);
                }
            }
        });

        return $saved;
    }

    /** A signed snapshot of existing stock and variant identities; no schema change. */
    public function inventoryRevision(Product $product): string
    {
        $rows = $product->getRelation('variants')->sortBy('id')->map(fn ($variant) => [
            $variant->id, (int) $variant->stock_quantity, (string) $variant->price,
            (bool) $variant->is_available, (string) $variant->updated_at,
        ])->values()->all();

        return hash_hmac('sha256', json_encode([$product->id, (string) $product->updated_at, $rows], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function removeImage(Product $product, ProductImage $image): void
    {
        abort_unless($image->product_id === $product->id, 403);
        DB::transaction(function () use ($product, $image): void {
            $primary = $image->is_primary;
            $image->delete();
            if ($primary) {
                $product->images()->whereNull('variant_id')->first()?->update(['is_primary' => true]);
            }
        });
        // A color file is stored once and shared by all its size combinations.
        if (! ProductImage::query()->where('path', $image->path)->exists()) {
            Storage::disk('public')->delete($image->path);
        }
    }

    private function generalImages(Product $product, array $data, array &$stored): void
    {
        $files = $data['images'] ?? [];
        if ($files === []) {
            return;
        }
        $explicit = isset($data['cover_index']);
        $cover = (int) ($data['cover_index'] ?? 0);
        $setPrimary = $explicit || ! $product->images()->where('is_primary', true)->exists();
        if ($explicit) {
            $product->images()->update(['is_primary' => false]);
        }
        foreach ($files as $index => $file) {
            $this->attachImage($product, $this->store($product, $file, $stored), null, $setPrimary && $index === $cover);
        }
    }

    private function removeColorImages(Product $product, array $colors, array &$removedPaths): void
    {
        $variants = $product->variants()->with('optionValues.option')->get();
        foreach ($colors as $color) {
            $ids = $color['remove_ids'] ?? [];
            if ($ids === []) {
                continue;
            }
            $variantIds = $variants->filter(fn ($variant) => $variant->optionValues->contains(fn ($value) => in_array(mb_strtolower($value->option->name), ['color', 'colour'], true) && $value->value === $color['value']))->modelKeys();
            $images = $product->images()->whereIn('variant_id', $variantIds)->whereIn('id', $ids)->get();
            if ($images->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['color_images' => 'Only remove photos belonging to this product color.']);
            }
            $paths = $images->pluck('path')->all();
            // One uploaded color file has a separate association for each size.
            $product->images()->whereIn('variant_id', $variantIds)->whereIn('path', $paths)->delete();
            array_push($removedPaths, ...$paths);
        }
    }

    private function colorImages(Product $product, array $colors, array &$stored, bool $colorFirst = false): void
    {
        $variants = $product->variants()->with(['optionValues.option', 'images'])->get();
        $groups = $variants->groupBy(fn ($variant) => $variant->optionValues->first(fn ($value) => in_array(mb_strtolower($value->option->name), ['color', 'colour'], true)
        )?->value ?? '');
        foreach ($groups as $colorValue => $sizes) {
            if ($colorValue === '') {
                continue;
            }
            $picturedSizes = $sizes->filter(fn ($variant) => $variant->images->isNotEmpty())->count();
            // Inherit photos common to all previously pictured sizes, keeping
            // individual size overrides on their own variant.
            $paths = $sizes->flatMap(fn ($variant) => $variant->images)
                ->groupBy('path')->filter(fn ($images) => $images->count() === $picturedSizes)->keys();
            foreach ($sizes as $variant) {
                foreach ($paths as $path) {
                    if (! $variant->images->contains('path', $path)) {
                        $this->attachImage($product, $path, $variant->id);
                    }
                }
            }
        }
        foreach ($colors as $color) {
            $matches = $variants->filter(fn ($variant) => $variant->optionValues->contains(fn ($value) => in_array(mb_strtolower($value->option->name), ['color', 'colour'], true) && $value->value === $color['value']
            ));
            $fileCount = count($color['files'] ?? []);
            if ($colorFirst && $fileCount > 0) {
                // sort_order is unsigned on MySQL. Make room at zero instead
                // of inserting negative priorities for newly uploaded photos.
                $product->images()->whereIn('variant_id', $matches->modelKeys())
                    ->increment('sort_order', $fileCount);
            }
            foreach ($color['files'] ?? [] as $offset => $file) {
                $path = $this->store($product, $file, $stored);
                foreach ($matches as $variant) {
                    $this->attachImage($product, $path, $variant->id, false, $colorFirst ? $offset : null);
                }
            }
        }
    }

    private function store(Product $product, UploadedFile $file, array &$stored): string
    {
        $path = $file->store('products/'.$product->id, 'public');
        if ($path === false) {
            throw new \RuntimeException('Could not store the uploaded product image.');
        }
        $stored[] = $path;

        return $path;
    }

    private function attachImage(Product $product, string $path, ?int $variantId, bool $primary = false, ?int $sortOrder = null): void
    {
        $product->images()->create([
            'variant_id' => $variantId,
            'path' => $path,
            'is_primary' => $primary,
            'sort_order' => $sortOrder ?? (int) $product->images()->max('sort_order') + 1,
        ]);
    }
}
