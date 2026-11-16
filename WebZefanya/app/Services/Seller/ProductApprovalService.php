<?php

namespace App\Services\Seller;

use App\Models\Admin\Admin;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductApprovalEvent;
use App\Models\Catalog\ProductRevision;
use App\Models\Seller\Seller;
use App\Notifications\Admin\ProductSubmitted;
use App\Notifications\Seller\ProductApprovalDecision;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Seller submissions stay off the public catalog until an admin approves them.
 * An approved listing keeps serving buyers while a material edit waits in a revision.
 */
class ProductApprovalService
{
    public function __construct(private StockService $stock) {}

    public function submissionStatus(Product $product, string $requested): string
    {
        if ($requested !== 'active' || $product->approved_at !== null || $product->status === 'active') {
            return $requested;
        }

        return 'pending';
    }

    public function isMaterial(Product $product, array $data): bool
    {
        if ($product->approved_at === null) {
            return false;
        }

        $product->loadMissing(['variants.optionValues', 'options.values', 'images', 'storeCategories']);

        if ($this->catalogTextChanged($product, $data) || $this->hasUploads($data) || $this->optionsChanged($product, $data) || $this->variantsChanged($product, $data)) {
            return true;
        }

        return $this->storeCategoriesChanged($product, $data);
    }

    /**
     * Keep the live listing. Stock still updates immediately so reservations stay current.
     *
     * @return Product the unchanged live product, with approval_queued set
     */
    public function queueRevision(Product $product, Seller $seller, array $data): Product
    {
        $this->applyStockDeltas($product, $data);
        $payload = $this->payload($product, $data);
        $existing = ProductRevision::query()
            ->where('product_id', $product->id)
            ->where('status', ProductRevision::PENDING)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $this->deleteStoredPayloadFiles($product, $existing->payload ?? []);
            $existing->forceFill([
                'seller_id' => $seller->id,
                'payload' => $payload,
                'rejection_reason' => null,
                'submitted_at' => now(),
            ])->save();
        } else {
            ProductRevision::query()->create([
                'product_id' => $product->id,
                'seller_id' => $seller->id,
                'status' => ProductRevision::PENDING,
                'payload' => $payload,
                'submitted_at' => now(),
            ]);
        }

        $fresh = $product->refresh();
        $fresh->setAttribute('approval_queued', true);
        DB::afterCommit(fn () => $this->notifyAdmins($fresh));

        return $fresh;
    }

    public function approve(Product $product, Admin $admin): Product
    {
        return DB::transaction(function () use ($product, $admin): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $revision = ProductRevision::query()
                ->where('product_id', $locked->id)
                ->where('status', ProductRevision::PENDING)
                ->lockForUpdate()
                ->first();

            if ($revision !== null) {
                $data = $this->withCurrentStock($locked, $revision->payload ?? []);
                $data['approval_apply'] = true;
                $data['inventory_revision'] = app(ProductPublishingService::class)->inventoryRevision($locked->load('variants'));
                $locked = app(ProductPublishingService::class)->save($locked, $locked->seller, $data);
                $revision->forceFill([
                    'status' => ProductRevision::APPROVED,
                    'reviewed_at' => now(),
                    'reviewed_by' => $admin->id,
                ])->save();
            } elseif ($locked->status === 'pending' || $locked->status === 'rejected') {
                app(ProductPublishingService::class)->assertPublishable($locked->load('variants', 'images', 'options'));
                $locked->forceFill([
                    'status' => 'active',
                    'approved_at' => $locked->approved_at ?? now(),
                    'rejection_reason' => null,
                ])->save();
            } elseif ($locked->approved_at === null && $locked->status === 'active') {
                $locked->forceFill(['approved_at' => now(), 'rejection_reason' => null])->save();
            } else {
                throw ValidationException::withMessages(['product' => 'This product is not waiting for approval.']);
            }

            if ($locked->approved_at === null) {
                $locked->forceFill(['approved_at' => now(), 'rejection_reason' => null])->save();
            }

            $this->record($admin, $locked, 'approved', null);
            DB::afterCommit(fn () => $locked->seller?->notify(new ProductApprovalDecision($locked, 'approved')));

            return $locked->refresh();
        });
    }

    public function reject(Product $product, Admin $admin, string $reason): Product
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }

        return DB::transaction(function () use ($product, $admin, $reason): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $revision = ProductRevision::query()
                ->where('product_id', $locked->id)
                ->where('status', ProductRevision::PENDING)
                ->lockForUpdate()
                ->first();

            if ($revision !== null) {
                $revision->forceFill([
                    'status' => ProductRevision::REJECTED,
                    'rejection_reason' => $reason,
                    'reviewed_at' => now(),
                    'reviewed_by' => $admin->id,
                ])->save();
            } elseif (in_array($locked->status, ['pending', 'rejected'], true)) {
                $locked->forceFill([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                ])->save();
            } else {
                throw ValidationException::withMessages(['product' => 'This product is not waiting for approval.']);
            }

            $this->record($admin, $locked, 'rejected', $reason);
            DB::afterCommit(fn () => $locked->seller?->notify(new ProductApprovalDecision($locked, 'rejected', $reason)));

            return $locked->refresh();
        });
    }

    public function notifyAdmins(Product $product): void
    {
        $product->loadMissing('seller');
        Admin::query()->whereIn('role', ['super_admin', 'admin', 'moderator'])->each(
            fn (Admin $admin) => $admin->notify(new ProductSubmitted($product))
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withCurrentStock(Product $product, array $data): array
    {
        $product->loadMissing('variants');
        $withVariants = filter_var($data['variants_exist'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $withVariants) {
            $data['stock_quantity'] = (int) ($product->variants->sortBy('id')->first()->stock_quantity ?? $product->stock_quantity);

            return $data;
        }

        foreach ($data['variants'] ?? [] as $index => $row) {
            if (empty($row['id'])) {
                continue;
            }
            $current = $product->variants->firstWhere('id', (int) $row['id']);
            if ($current !== null) {
                $data['variants'][$index]['stock_quantity'] = $current->stock_quantity;
            }
        }

        return $data;
    }

    private function applyStockDeltas(Product $product, array $data): void
    {
        $product->loadMissing('variants');
        $withVariants = filter_var($data['variants_exist'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $withVariants) {
            $variant = $product->variants->sortBy('id')->first();
            if ($variant !== null && array_key_exists('stock_quantity', $data)) {
                $this->stock->set($variant, $data['stock_quantity']);
                $product->syncVariantRollups();
            }

            return;
        }

        foreach ($data['variants'] ?? [] as $row) {
            if (empty($row['id'])) {
                continue;
            }
            $variant = $product->variants->firstWhere('id', (int) $row['id']);
            if ($variant !== null && array_key_exists('stock_quantity', $row)) {
                $this->stock->set($variant, $row['stock_quantity']);
            }
        }
        $product->syncVariantRollups();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(Product $product, array $data): array
    {
        $payload = $data;
        unset($payload['images'], $payload['color_images'], $payload['variant_images'], $payload['approval_apply']);
        $payload['stored_image_paths'] = $this->storeFiles($product, $data['images'] ?? []);
        $payload['stored_color_images'] = [];
        foreach ($data['color_images'] ?? [] as $color) {
            $payload['stored_color_images'][] = [
                'value' => $color['value'] ?? '',
                'remove_ids' => $color['remove_ids'] ?? [],
                'paths' => $this->storeFiles($product, $color['files'] ?? []),
            ];
        }
        $payload['stored_variant_images'] = [];
        foreach ($data['variant_images'] ?? [] as $variantId => $files) {
            $payload['stored_variant_images'][(string) $variantId] = $this->storeFiles($product, $files);
        }

        return $payload;
    }

    /**
     * @param  array<int, mixed>  $files
     * @return array<int, string>
     */
    private function storeFiles(Product $product, array $files): array
    {
        $paths = [];
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $path = \App\Support\ImageSanitizer::store($file, 'products/'.$product->id.'/pending', 'public', 'images');
            $paths[] = $path;
        }

        return $paths;
    }

    /** @param  array<string, mixed>  $payload */
    private function deleteStoredPayloadFiles(Product $product, array $payload): void
    {
        $paths = $payload['stored_image_paths'] ?? [];
        foreach ($payload['stored_color_images'] ?? [] as $color) {
            array_push($paths, ...($color['paths'] ?? []));
        }
        foreach ($payload['stored_variant_images'] ?? [] as $variantPaths) {
            array_push($paths, ...$variantPaths);
        }
        $live = $product->images()->pluck('path')->all();
        $pending = array_values(array_diff($paths, $live));
        if ($pending !== []) {
            Storage::disk('public')->delete($pending);
        }
    }

    private function record(Admin $admin, Product $product, string $decision, ?string $reason): void
    {
        ProductApprovalEvent::query()->create([
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'admin_email' => $admin->email,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'seller_name' => $product->seller?->business_name ?? 'Unknown',
            'decision' => $decision,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function catalogTextChanged(Product $product, array $data): bool
    {
        $withVariants = filter_var($data['variants_exist'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $fields = [
            'name' => (string) $product->name,
            'description' => (string) ($product->description ?? ''),
            'brand' => (string) ($product->brand ?? ''),
            'condition' => (string) ($product->condition ?? ''),
        ];
        if (\App\Support\SizeGuide::columnExists()) {
            $fields['garment_type'] = (string) ($product->garment_type ?? '');
        }
        foreach ($fields as $key => $current) {
            if (array_key_exists($key, $data) && trim((string) ($data[$key] ?? '')) !== trim($current)) {
                return true;
            }
        }
        if (array_key_exists('category_id', $data) && (int) $data['category_id'] !== (int) $product->category_id) {
            return true;
        }
        if (array_key_exists('price', $data) && round((float) $data['price'], 2) !== round((float) $product->price, 2)) {
            return true;
        }
        if (! $withVariants && array_key_exists('weight_grams', $data) && (int) $data['weight_grams'] !== (int) $product->weight_grams) {
            return true;
        }
        if (array_key_exists('dimensions', $data)) {
            $current = $product->dimensions ?? [];
            foreach (['length', 'width', 'height'] as $edge) {
                if (round((float) ($data['dimensions'][$edge] ?? 0), 2) !== round((float) ($current[$edge] ?? 0), 2)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $data */
    private function hasUploads(array $data): bool
    {
        if ($this->storeable($data['images'] ?? [])) {
            return true;
        }
        foreach ($data['color_images'] ?? [] as $color) {
            if (($color['remove_ids'] ?? []) !== [] || $this->storeable($color['files'] ?? [])) {
                return true;
            }
        }
        foreach ($data['variant_images'] ?? [] as $files) {
            if ($this->storeable($files)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, mixed>  $files */
    private function storeable(array $files): bool
    {
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $data */
    private function optionsChanged(Product $product, array $data): bool
    {
        $withVariants = filter_var($data['variants_exist'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $current = $product->options->map(fn ($option): array => [
            'name' => $option->name,
            'values' => $option->values->pluck('value')->all(),
        ])->values()->all();
        $submitted = $withVariants ? array_values($data['options'] ?? []) : [];

        return json_encode($current) !== json_encode($submitted);
    }

    /** @param  array<string, mixed>  $data */
    private function variantsChanged(Product $product, array $data): bool
    {
        $withVariants = filter_var($data['variants_exist'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $withVariants) {
            $variant = $product->variants->sortBy('id')->first();
            if ($variant === null) {
                return false;
            }

            return round((float) ($data['price'] ?? $variant->price), 2) !== round((float) $variant->price, 2);
        }

        $current = [];
        foreach ($product->variants as $variant) {
            if (! $variant->hasOptions()) {
                continue;
            }
            $current[$variant->id] = [
                'sku' => (string) ($variant->sku ?? ''),
                'price' => round((float) $variant->price, 2),
                'weight' => (int) $variant->weight_grams,
                'available' => (bool) $variant->is_available,
                'values' => $variant->optionValues->pluck('value')->all(),
            ];
        }
        $seen = [];
        foreach ($data['variants'] ?? [] as $row) {
            $signature = [
                'sku' => (string) ($row['sku'] ?? ''),
                'price' => round((float) ($row['price'] ?? 0), 2),
                'weight' => (int) ($row['weight_grams'] ?? 0),
                'available' => filter_var($row['is_available'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'values' => array_values($row['values'] ?? []),
            ];
            if (empty($row['id']) || ! isset($current[(int) $row['id']]) || $current[(int) $row['id']] !== $signature) {
                return true;
            }
            $seen[] = (int) $row['id'];
        }

        return count($seen) !== count($current);
    }

    /** @param  array<string, mixed>  $data */
    private function storeCategoriesChanged(Product $product, array $data): bool
    {
        if (! array_key_exists('store_category_ids', $data) && blank($data['new_store_category'] ?? null)) {
            return false;
        }
        if (filled($data['new_store_category'] ?? null)) {
            return true;
        }
        $current = $product->storeCategories->modelKeys();
        $submitted = array_map('intval', $data['store_category_ids'] ?? []);
        sort($current);
        sort($submitted);

        return $current !== $submitted;
    }
}
