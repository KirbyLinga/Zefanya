<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductImage;
use App\Models\Seller\Seller;
use App\Models\Shared\OrderItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductPublishingService
{
    public function __construct(private VariantSyncService $variants) {}

    /** Save a listing and all its inventory/image associations as one operation. */
    public function save(Product $product, Seller $seller, array $data): Product
    {
        // Validate submitted prices before decimal casts or variant rollups can round them.
        $draft = ($data['status'] ?? null) === 'draft';
        $priceRules = [$draft ? 'nullable' : 'required', 'numeric', 'decimal:0,2', $draft ? 'min:0' : 'min:1', 'max:1000000'];
        Validator::make($data, [
            'price' => $priceRules,
            ...(filter_var($data['variants_exist'] ?? false, FILTER_VALIDATE_BOOLEAN)
                ? ['variants.*.price' => $priceRules] : []),
        ])->validate();

        if (! filter_var($data['variants_exist'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            StockService::validateSellerQuantity($data['stock_quantity'] ?? 0);
        }
        $stored = [];
        $removedPaths = [];
        try {
            $saved = DB::transaction(function () use ($product, $seller, $data, &$stored, &$removedPaths): Product {
                $currentSeller = Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
                $this->assertSellerCanSaveStatus($currentSeller, $data['status']);
                if (! $product->exists) {
                    $existing = Product::withTrashed()->where('seller_id', $seller->id)
                        ->where('creation_key', $data['creation_key'])->first();
                    if ($existing !== null) {
                        return $existing;
                    }
                    $product->forceFill(['creation_key' => $data['creation_key']]);
                }
                $data = array_replace(['price' => 0, 'stock_quantity' => 0], $data);
                $data['price'] ??= 0;
                $data['stock_quantity'] ??= 0;
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
                    'category_id' => $data['category_id'] ?? $product->category_id,
                    'name' => $data['name'],
                    'slug' => ! $product->exists || $data['name'] !== $product->name
                        ? Product::generateUniqueSlug($data['name']) : $product->slug,
                    'description' => $data['description'] ?? null,
                    'brand' => $data['brand'] ?? $product->brand ?? 'No brand',
                    'condition' => $data['condition'] ?? $product->condition ?? 'New',
                    'dimensions' => $data['dimensions'] ?? null,
                    'price' => $data['price'],
                    'weight_grams' => $withVariants ? (min(array_column($data['variants'], 'weight_grams') ?: [0])) : ($data['weight_grams'] ?? 0),
                    // Newly created inventory starts at zero; StockService writes its ledger.
                    'stock_quantity' => ! $product->exists ? 0 : $data['stock_quantity'],
                    'low_stock_threshold' => $data['low_stock_threshold'] ?? 5,
                ]);
                $product->forceFill(['status' => $data['status']]);
                $product->save();
                if (array_key_exists('store_category_ids', $data) || filled($data['new_store_category'] ?? null)) {
                    $storeCategories = app(StoreCategoryService::class);
                    $ids = $data['store_category_ids'] ?? $product->storeCategories()->pluck('store_categories.id')->all();
                    if (filled($data['new_store_category'] ?? null)) {
                        $ids[] = $storeCategories->findOrCreate($seller, $data['new_store_category'])->id;
                    }
                    $storeCategories->assign($seller, $product, array_values(array_unique(array_map('intval', $ids))));
                }
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
                if ($product->images()->whereNull('variant_id')->count() > 9) {
                    throw ValidationException::withMessages(['images' => 'A product can have at most 9 JPG/PNG images, 5 MB each.']);
                }
                if ($product->status === 'active') {
                    $this->assertPublishable($product);
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

    public function assertPublishable(Product $product): void
    {
        Validator::make($product->getAttributes(), [
            'name' => ['required', 'string', 'min:5', 'max:120'],
            'description' => ['required', 'string', 'min:20', 'max:3000'],
            'category_id' => ['required', 'exists:categories,id'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:1000000'],
        ])->validate();
        Validator::make(['dimensions' => $product->dimensions], [
            'dimensions' => ['required', 'array:length,width,height'],
            'dimensions.length' => ['required', 'numeric', 'gt:0'],
            'dimensions.width' => ['required', 'numeric', 'gt:0'],
            'dimensions.height' => ['required', 'numeric', 'gt:0'],
        ])->validate();
        Validator::make(['variants' => $product->variants()->where('is_available', true)->get()->toArray()], [
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.price' => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:1000000'],
            'variants.*.stock_quantity' => ['required', 'integer', 'min:0', 'max:99999'],
        ])->validate();
        $images = $product->images()->whereNull('variant_id')->pluck('path');
        if ($images->isEmpty() || $images->count() > 9) {
            throw ValidationException::withMessages(['images' => 'Upload 1–9 JPG/PNG product photos, at most 5 MB each.']);
        }
        $weights = $product->options()->exists()
            ? $product->variants()->where('is_available', true)->pluck('weight_grams')->all()
            : [$product->weight_grams];
        foreach ($weights ?: [null] as $weight) {
            if ($weight === null || $weight < 1 || $weight > 100000) {
                throw ValidationException::withMessages(['weight' => 'Enter a weight from 1 g to 100 kg for the product and each selling variant before publishing.']);
            }
        }
    }

    public function setStatus(Product $product, Seller $seller, string $status): Product
    {
        return DB::transaction(function () use ($product, $seller, $status): Product {
            $currentSeller = Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->seller_id === $seller->id, 403);
            $this->assertSellerCanSaveStatus($currentSeller, $status);
            if ($status === 'active') {
                $this->assertPublishable($locked);
            }
            $locked->forceFill(['status' => $status])->save();

            return $locked;
        }, 3);
    }

    private function assertSellerCanSaveStatus(Seller $seller, string $status): void
    {
        if (! $seller->isApproved() && $status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Your store is not approved for publishing yet. Save the product as a draft.',
            ]);
        }
    }

    public function archive(Product $product, Seller $seller): bool
    {
        return DB::transaction(function () use ($product, $seller): bool {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->seller_id === $seller->id, 403);
            if (OrderItem::query()->where('product_id', $locked->id)->whereHas('sellerOrder', fn ($query) => $query
                ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value, OrderStatus::Returned->value]))->exists()) {
                return false;
            }
            $locked->delete();

            return true;
        }, 3);
    }

    public function duplicate(Product $product, Seller $seller): Product
    {
        return DB::transaction(function () use ($product, $seller): Product {
            Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $source = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_unless($source->seller_id === $seller->id, 403);
            $source->load(['options.values', 'variants.optionValues.option', 'images', 'storeCategories']);
            $copy = $source->replicate(['slug', 'creation_key']);
            $copy->forceFill(['status' => 'draft', 'stock_quantity' => 0, 'slug' => Product::generateUniqueSlug($source->name), 'creation_key' => (string) Str::uuid()])->save();
            $options = $source->options->map(fn ($option) => ['name' => $option->name, 'values' => $option->values->pluck('value')->all()])->all();
            $variants = $source->variants->filter->hasOptions()->map(fn ($variant) => [
                'values' => $variant->optionValues->pluck('value')->all(), 'sku' => null,
                'price' => $variant->price, 'stock_quantity' => $variant->stock_quantity,
                'weight_grams' => $variant->weight_grams, 'is_available' => $variant->is_available,
            ])->values()->all();
            $copy->stock_quantity = $source->stock_quantity;
            $this->variants->sync($copy, $options, $variants, $options !== []);
            $map = [];
            $copiedVariants = $copy->variants()->with('optionValues')->get();
            foreach ($source->variants as $variant) {
                $signature = $variant->optionValues->pluck('value')->all();
                $map[$variant->id] = $copiedVariants->first(fn ($row) => $row->optionValues->pluck('value')->all() === $signature)?->id;
            }
            foreach ($source->images as $image) {
                $this->attachImage($copy, $image->path, $image->variant_id === null ? null : ($map[$image->variant_id] ?? null), $image->is_primary, $image->sort_order);
            }
            $copy->storeCategories()->sync($source->storeCategories->modelKeys());

            return $copy->refresh();
        }, 3);
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
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'active' && $image->variant_id === null
                && ! $locked->images()->whereNull('variant_id')->whereKeyNot($image->id)->exists()) {
                throw ValidationException::withMessages(['images' => 'An active product must retain at least one gallery photo.']);
            }
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
