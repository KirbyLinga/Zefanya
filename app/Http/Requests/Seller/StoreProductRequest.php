<?php

namespace App\Http\Requests\Seller;

use App\Models\ProductVariant;
use App\Services\VariantSyncService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    /**
     * The seller form submits option/variant values as comma-joined strings
     * ("Red, Blue, Green" — one text/hidden input per axis), while validation
     * and the sync service want list-shaped arrays. Normalize BEFORE the
     * rules run so everything downstream is uniform.
     */
    protected function prepareForValidation(): void
    {
        $split = static function ($values): array {
            $parts = is_string($values) ? explode(',', $values) : Arr::wrap($values ?? []);

            return collect($parts)
                ->map(fn ($v) => trim((string) $v))
                ->filter(fn (string $v): bool => $v !== '')
                ->values()
                ->all();
        };

        $options = collect(Arr::wrap($this->input('options')))
            ->map(fn ($option): array => [
                'name' => trim((string) ($option['name'] ?? '')),
                'values' => $split($option['values'] ?? null),
            ])
            // The form always renders both option rows, so a legacy
            // single-SKU submit still carries blank rows — drop those, but
            // keep a half-filled axis so its validation error surfaces.
            ->reject(fn (array $option): bool => $option['name'] === '' && $option['values'] === [])
            ->values()
            ->all();

        $variants = collect(Arr::wrap($this->input('variants')))
            ->map(fn ($row): array => [
                'id' => $row['id'] ?? null,
                'sku' => trim((string) ($row['sku'] ?? '')) ?: null,
                'price' => $row['price'] ?? null,
                'stock_quantity' => $row['stock_quantity'] ?? null,
                'is_available' => $row['is_available'] ?? true,
                'values' => $split($row['values'] ?? null),
            ])
            // Same story: the generated table's hidden inputs survive in the
            // DOM on a legacy (variants_exist=0) submit — ignore value-less rows.
            ->reject(fn (array $row): bool => $row['values'] === [])
            ->values()
            ->all();

        $this->merge([
            'options' => $options,
            'variants' => $variants,
        ]);
    }

    public function authorize(): bool
    {
        return true; // seller-guard middleware already gates access to this route
    }

    /**
     * Cross-row SKU rules the base rules cannot express: a non-blank SKU must
     * be unique (case-insensitive) among the variants of THIS seller's other
     * products, and twice within one submitted payload.
     *
     * Runs after the base rules so rows that already failed `max:64` (and the
     * whole payload when `variants` itself is malformed) are skipped instead of
     * spending a query on them.
     */
    public function after(Validator $validator): array
    {
        return [
            function () use ($validator): void {
                $this->validateVariantSkus($validator);
                if ($validator->errors()->isEmpty()) {
                    if ($this->boolean('variants_exist')) {
                        foreach (app(VariantSyncService::class)->configurationErrors($this->input('options', []), $this->input('variants', []), ! $this->boolean('color_first')) as $key => $message) {
                            $validator->errors()->add($key, $message);
                        }
                    }
                    $this->validateImageAssignments($validator);
                    $this->validateColorFirst($validator);
                }
            },
        ];
    }

    private function validateVariantSkus(Validator $validator): void
    {
        // Structural failures on any variant row mean the payload isn't
        // trustworthy yet — don't pile SKU errors on top of them.
        $hasVariantErrors = collect($validator->errors()->keys())
            ->contains(fn (string $key): bool => str_starts_with($key, 'variants.'));

        if ($hasVariantErrors) {
            return;
        }

        $rows = array_values((array) $this->input('variants', []));
        if ($rows === []) {
            return;
        }

        $sellerId = Auth::guard('seller')->id();
        if ($sellerId === null) {
            return;
        }

        // Editing a product may re-submit its own SKUs — only collide with
        // OTHER products of the same seller (soft-deleted ones drop out of the
        // whereHas through Product's global scope, freeing their SKUs).
        $editingProductId = $this->route('product')?->id;

        $usedElsewhere = ProductVariant::query()
            ->whereHas('product', fn (Builder $query): Builder => $query->where('seller_id', $sellerId))
            ->when(
                $editingProductId !== null,
                fn (Builder $query): Builder => $query->where('product_id', '!=', $editingProductId),
            )
            ->whereNotNull('sku')
            ->pluck('sku')
            // Compare case-insensitively: "shirt-blk-m" === "SHIRT-BLK-M".
            ->mapWithKeys(fn (string $sku): array => [mb_strtolower($sku) => $sku])
            ->all();

        $seenInPayload = [];

        foreach ($rows as $index => $row) {
            $attribute = "variants.$index.sku";

            // Already failed a base rule (e.g. max:64) — one error is enough.
            if ($validator->errors()->has($attribute)) {
                continue;
            }

            // Blank SKUs normalize to NULL (prepareForValidation) and NULLs
            // never collide, so skipped SKUs / Default variants are exempt.
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $key = mb_strtolower($sku);

            if (isset($seenInPayload[$key])) {
                $validator->errors()->add(
                    $attribute,
                    "SKU \"{$sku}\" is already used on another variant of this product."
                );

                continue;
            }

            if (isset($usedElsewhere[$key])) {
                $validator->errors()->add(
                    $attribute,
                    "SKU \"{$sku}\" is already used on another of the seller's products."
                );

                continue;
            }

            $seenInPayload[$key] = true;
        }
    }

    private function validateImageAssignments(Validator $validator): void
    {
        if ($this->filled('cover_index') && ! array_key_exists((int) $this->input('cover_index'), $this->file('images', []))) {
            $validator->errors()->add('cover_index', 'Choose a cover from the uploaded product photos.');
        }
        $color = collect($this->input('options', []))->first(fn ($option) => in_array(mb_strtolower($option['name']), ['color', 'colour'], true));
        $seen = [];
        foreach ($this->input('color_images', []) as $index => $image) {
            $value = $image['value'] ?? '';
            if (! $this->boolean('variants_exist') || ! in_array($value, $color['values'] ?? [], true) || in_array($value, $seen, true)) {
                $validator->errors()->add("color_images.$index.value", 'Choose a unique color from this product variation group.');
            }
            $seen[] = $value;
        }
        $product = $this->route('product');
        $allowed = $product?->variants()->pluck('id')->all() ?? [];
        $saved = $product?->variants()->with(['images', 'optionValues.option'])->get() ?? collect();
        foreach ($this->input('color_images', []) as $index => $colorImage) {
            $colorValue = $colorImage['value'] ?? '';
            $submittedIds = collect($this->input('variants', []))->filter(fn ($row) => in_array($colorValue, $row['values'], true))->pluck('id')->filter();
            $allowedImages = $saved->filter(fn ($variant) => $submittedIds->contains($variant->id) || $variant->optionValues->contains(fn ($value) => in_array(mb_strtolower($value->option->name), ['color', 'colour'], true) && $value->value === $colorValue))->flatMap(fn ($variant) => $variant->images)->pluck('id')->all();
            if (array_diff($colorImage['remove_ids'] ?? [], $allowedImages) !== []) {
                $validator->errors()->add("color_images.$index.remove_ids", 'Only remove photos belonging to this product color.');
            }
        }
        foreach (array_keys($this->file('variant_images', [])) as $id) {
            if (! in_array((int) $id, $allowed, true)) {
                $validator->errors()->add('variant_images', 'Choose a variant belonging to this product.');
            }
        }
    }

    private function validateColorFirst(Validator $validator): void
    {
        $product = $this->route('product');
        $ids = collect($this->input('variants', []))->pluck('id')->filter()->all();
        $owned = $product?->variants()->pluck('id')->all() ?? [];
        if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $owned) !== []) {
            $validator->errors()->add('variants', 'Each saved variant must belong to this product and appear once.');
        }
        if (! $this->boolean('color_first') || ! $this->boolean('variants_exist')) {
            return;
        }
        $options = $this->input('options', []);
        if (count($options) !== 2 || ! in_array(mb_strtolower($options[0]['name']), ['color', 'colour'], true) || mb_strtolower($options[1]['name']) !== 'size') {
            $validator->errors()->add('options', 'Use Color followed by Size for the color-first editor.');

            return;
        }
        $rows = collect($this->input('variants', []));
        // Existing images count only for the exact retained variant identity/combination.
        $saved = $product?->variants()->with(['optionValues.option', 'images'])->get() ?? collect();
        $existing = $saved->keyBy('id');
        foreach ($options[0]['values'] as $color) {
            $matches = $rows->filter(fn ($row) => ($row['values'][0] ?? null) === $color);
            if ($matches->isEmpty()) {
                $validator->errors()->add('variants', 'Every color needs at least one available size.');
            }
            $upload = collect($this->input('color_images', []))->search(fn ($image) => ($image['value'] ?? '') === $color);
            $hasUpload = $upload !== false && count($this->file("color_images.$upload.files", [])) > 0;
            $removeIds = $upload === false ? [] : $this->input("color_images.$upload.remove_ids", []);
            $removedPaths = $saved->flatMap(fn ($variant) => $variant->images)->whereIn('id', $removeIds)->pluck('path')->all();
            $hasSaved = $matches->contains(function ($row) use ($existing, $saved, $color, $removedPaths) {
                if (! empty($row['id'])) {
                    return $existing->get($row['id'])?->images->whereNotIn('path', $removedPaths)->isNotEmpty() ?? false;
                }

                return $saved->contains(fn ($variant) => $variant->images->whereNotIn('path', $removedPaths)->isNotEmpty() && $variant->optionValues->contains(fn ($value) => in_array(mb_strtolower($value->option->name), ['color', 'colour'], true) && $value->value === $color));
            });
            if (! $hasUpload && ! $hasSaved) {
                $validator->errors()->add('color_images', "Upload an image specifically for $color.");
            }
        }
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

        return [
            'color_first' => ['nullable', 'boolean'],
            'variants.*.id' => ['nullable', 'integer', 'min:1'],
            'inventory_revision' => [$this->isMethod('PUT') || $this->isMethod('PATCH') ? 'required' : 'nullable', 'string', 'size:64'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['draft', 'active', 'inactive'])],
            'images' => [$isUpdate ? 'nullable' : 'required', 'array', 'max:6'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'cover_index' => ['nullable', 'integer', 'min:0'],
            'color_images' => ['nullable', 'array', 'max:50'],
            'color_images.*.value' => ['required_with:color_images', 'string', 'max:100'],
            'color_images.*.remove_ids' => ['nullable', 'array', 'max:100'],
            'color_images.*.remove_ids.*' => ['integer', 'min:1', 'distinct'],
            'color_images.*.files' => ['nullable', 'array', 'max:6'],
            'color_images.*.files.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'variant_images' => ['nullable', 'array', 'max:50'],
            'variant_images.*' => ['array', 'max:6'],
            'variant_images.*.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],

            // ── Variant Options (optional) ──────────────────────────────
            // variants_exist=0 → legacy single-SKU product (no options UI shown).
            // variants_exist=1 → the options/variants payloads must be well-formed.
            'variants_exist' => ['nullable', 'boolean'],

            'options' => ['required_if:variants_exist,1', 'nullable', 'array', 'max:2'],
            'options.*.name' => ['required_with:options', 'string', 'max:50'],
            'options.*.values' => ['required_with:options', 'array', 'min:1', 'max:50'],
            'options.*.values.*' => ['string', 'max:100'],

            'variants' => ['required_if:variants_exist,1', 'nullable', 'array', 'max:50'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.price' => ['required_with:variants', 'numeric', 'min:0', 'max:9999999.99'],
            'variants.*.stock_quantity' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.is_available' => ['nullable', 'boolean'],
            'variants.*.values' => ['required_with:variants', 'array'],
            'variants.*.values.*' => ['string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'images.required' => 'Upload at least one product photo.',
            'images.*.image' => 'Each file must be an image (JPG, PNG, or WEBP).',
            'images.*.max' => 'Each image must be smaller than 3MB.',
            'price.min' => 'Price cannot be negative.',
            'options.max' => 'A product can have at most two option axes (e.g. Color, Size).',
            'options.*.values.min' => 'Each option needs at least one value.',
        ];
    }

    /**
     * Normalized variant payload for the controller/service: list-shaped
     * options and variant rows keyed by their combination signature.
     *
     * @return array{options: array<int, array{name: string, values: array<int, string>}>, variants: array<int, array<string, mixed>>}
     */
    public function variantPayload(): array
    {
        if (! $this->boolean('variants_exist')) {
            return ['options' => [], 'variants' => []];
        }

        $options = [];
        foreach ($this->input('options', []) as $option) {
            $name = trim((string) ($option['name'] ?? ''));
            $values = array_values(array_filter(array_map(
                fn ($v) => trim((string) $v),
                Arr::wrap($option['values'] ?? []),
            )));

            if ($name !== '' && $values !== []) {
                $options[] = ['name' => $name, 'values' => $values];
            }
        }

        $variants = [];
        foreach ($this->input('variants', []) as $row) {
            $variants[] = [
                'id' => $row['id'] ?? null,
                'sku' => trim((string) ($row['sku'] ?? '')) ?: null,
                'price' => $row['price'],
                'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
                // (bool) '0' is true in PHP — cast through filter_var so the
                // form's '0'/'1' flags mean what they say.
                'is_available' => filter_var($row['is_available'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'values' => array_values(array_map(fn ($v) => trim((string) $v), Arr::wrap($row['values'] ?? []))),
            ];
        }

        return ['options' => $options, 'variants' => $variants];
    }
}
