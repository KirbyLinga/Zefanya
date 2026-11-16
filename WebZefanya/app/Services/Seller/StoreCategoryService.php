<?php

namespace App\Services\Seller;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StoreCategoryService
{
    /** Typing a name the store already has reuses that category instead of failing. */
    public function findOrCreate(Seller $seller, string $name): StoreCategory
    {
        $name = trim($name);

        return StoreCategory::query()->where('seller_id', $seller->id)
            ->where('normalized_name', mb_strtolower($name))->first()
            ?? $this->save($seller, ['name' => $name]);
    }

    public function save(Seller $seller, array $input, ?StoreCategory $category = null): StoreCategory
    {
        abort_if($category !== null && (int) $category->seller_id !== (int) $seller->id, 404);
        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']);
        }
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:50'], 'parent_id' => ['nullable', 'integer']])->validate();

        return DB::transaction(function () use ($seller, $category, $data): StoreCategory {
            // Serialize tree changes within one store, including reparenting.
            Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $current = $category === null ? new StoreCategory : StoreCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $current->parent_id;
            if ($parentId !== null) {
                $parent = StoreCategory::query()->where('seller_id', $seller->id)->whereKey($parentId)->lockForUpdate()->first();
                if ($parent === null || $parent->parent_id !== null || ($current->exists && ($parent->id === $current->id || $current->children()->exists()))) {
                    throw ValidationException::withMessages(['parent_id' => 'Choose a top-level category in your store. Store categories can have only two levels.']);
                }
            }
            $normalized = mb_strtolower($data['name']);
            if (StoreCategory::query()->where('seller_id', $seller->id)->where('normalized_name', $normalized)
                ->when($current->exists, fn ($query) => $query->where('id', '!=', $current->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'This store category already exists.']);
            }
            if (! $current->exists || $current->parent_id != $parentId) {
                $current->forceFill(['sort_order' => 1 + (int) StoreCategory::query()->where('seller_id', $seller->id)
                    ->where('parent_id', $parentId)->max('sort_order')]);
            }
            $current->forceFill(['seller_id' => $seller->id, 'parent_id' => $parentId, 'name' => $data['name'], 'normalized_name' => $normalized])->save();

            return $current;
        }, 3);
    }

    public function reorder(Seller $seller, array $ids, ?int $parentId = null): void
    {
        Validator::make(['category_ids' => $ids], [
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['required', 'integer', 'distinct'],
        ])->validate();
        DB::transaction(function () use ($seller, $ids, $parentId): void {
            Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            if ($parentId !== null) {
                StoreCategory::query()->where('seller_id', $seller->id)->findOrFail($parentId);
            }
            $siblings = StoreCategory::query()->where('seller_id', $seller->id)->where('parent_id', $parentId)->lockForUpdate()->get();
            $ordered = array_map('intval', $ids);
            if (count($ordered) !== $siblings->count() || array_diff($ordered, $siblings->modelKeys()) !== []) {
                throw ValidationException::withMessages(['category_ids' => 'Include each category from the same store and parent exactly once.']);
            }
            foreach ($ordered as $position => $id) {
                $siblings->firstWhere('id', $id)->forceFill(['sort_order' => $position])->save();
            }
        }, 3);
    }

    public function move(Seller $seller, StoreCategory $category, string $direction): void
    {
        abort_unless((int) $category->seller_id === (int) $seller->id, 404);
        DB::transaction(function () use ($seller, $category, $direction): void {
            Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $current = StoreCategory::query()->where('seller_id', $seller->id)->lockForUpdate()->findOrFail($category->id);
            $ids = StoreCategory::query()->where('seller_id', $seller->id)->where('parent_id', $current->parent_id)->displayOrder()->pluck('id')->all();
            $position = array_search($current->id, $ids, true);
            $next = $position + ($direction === 'up' ? -1 : 1);
            if (isset($ids[$next])) {
                [$ids[$position], $ids[$next]] = [$ids[$next], $ids[$position]];
                $this->reorder($seller, $ids, $current->parent_id);
            }
        }, 3);
    }

    public function setVisibility(Seller $seller, StoreCategory $category, bool $visible): void
    {
        abort_unless((int) $category->seller_id === (int) $seller->id, 404);
        DB::transaction(function () use ($seller, $category, $visible): void {
            Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            StoreCategory::query()->where('seller_id', $seller->id)->lockForUpdate()->findOrFail($category->id)
                ->forceFill(['is_visible' => $visible])->save();
        }, 3);
    }

    public function deletionPreview(Seller $seller, StoreCategory $category): array
    {
        abort_unless((int) $category->seller_id === (int) $seller->id, 404);
        $category = StoreCategory::query()->where('seller_id', $seller->id)->findOrFail($category->id);
        $children = $category->children()->where('seller_id', $seller->id)->get();
        $ids = [$category->id, ...$children->modelKeys()];
        if (StoreCategory::query()->whereIn('parent_id', $ids)->whereNotIn('id', $ids)->exists()) {
            throw ValidationException::withMessages(['category' => 'This category has an unexpected subtree. Review its structure before deletion.']);
        }
        $memberships = DB::table('product_store_category')->whereIn('store_category_id', $ids)
            ->orderBy('store_category_id')->orderBy('product_id')->get();
        if (Product::withoutGlobalScopes()->whereIn('id', $memberships->pluck('product_id'))->where('seller_id', '!=', $seller->id)->exists()) {
            throw ValidationException::withMessages(['category' => 'Cross-store memberships require reconciliation before deletion.']);
        }
        $uncategorized = Product::withTrashed()->where('seller_id', $seller->id)->whereIn('id', $memberships->pluck('product_id'))
            ->whereDoesntHave('storeCategories', fn ($query) => $query->whereNotIn('store_categories.id', $ids))->count();

        return [
            'category' => $category, 'children' => $children, 'category_ids' => $ids,
            'affected_count' => $memberships->pluck('product_id')->unique()->count(),
            'uncategorized_count' => $uncategorized,
            'revision' => hash_hmac('sha256', json_encode([$category->getAttributes(), $children->toArray(), $memberships->all()], JSON_THROW_ON_ERROR), (string) config('app.key')),
        ];
    }

    public function remove(Seller $seller, StoreCategory $category, string $revision): void
    {
        DB::transaction(function () use ($seller, $category, $revision): void {
            Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $preview = $this->deletionPreview($seller, $category);
            if (! hash_equals($preview['revision'], $revision)) {
                throw ValidationException::withMessages(['category' => 'Category contents changed. Review the deletion warning again.']);
            }
            DB::table('product_store_category')->whereIn('store_category_id', $preview['category_ids'])->delete();
            // The foreign keys remain restrictive: explicitly delete children before their parent.
            StoreCategory::query()->where('seller_id', $seller->id)->whereIn('id', $preview['children']->modelKeys())->delete();
            $preview['category']->delete();
        }, 3);
    }

    public function assign(Seller $seller, Product $product, array $ids): void
    {
        abort_unless((int) $product->seller_id === (int) $seller->id, 404);
        $data = Validator::make(['store_category_ids' => $ids], [
            'store_category_ids' => ['array', 'max:3'],
            'store_category_ids.*' => ['required', 'integer', 'distinct'],
        ], ['store_category_ids.max' => 'A product can belong to at most three sub-categories.'])->validate();

        DB::transaction(function () use ($seller, $product, $data): void {
            Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $current = Product::query()->where('seller_id', $seller->id)->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $ids = array_map('intval', $data['store_category_ids']);
            $categories = StoreCategory::query()->where('seller_id', $seller->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($categories->count() !== count($ids)) {
                throw ValidationException::withMessages(['store_category_ids' => 'Choose categories belonging to your store.']);
            }
            $current->storeCategories()->sync($ids);
        }, 3);
    }
}
