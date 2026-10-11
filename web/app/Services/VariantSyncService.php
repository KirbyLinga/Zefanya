<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Applies a seller's Variant Options submission to a product.
 *
 * Input contract (validated by StoreProductRequest):
 *   options:        [{ name: "Color", values: ["Red", "Blue"] }, ...]  (0–2)
 *   variants:       [{ sku, price, stock_quantity, is_available, values: ["Red","M"] }, ...]
 *   variants_exist: bool — when false the seller kept legacy single-SKU mode
 *                   and only the auto Default variant is maintained.
 *
 * Behaviours:
 *   - Options/values are recreated wholesale from the submission (diff-by-text
 *     was rejected as over-engineering for ≤2 axes; see Phase 1 report).
 *   - Variant rows are matched back by their option-value SIGNATURE
 *     ("Red|M"), so an unchanged combination keeps its SKU/price/stock while
 *     a removed combination's row is deleted. Cart lines pointing at a
 *     dropped variant cascade away with it — the buyer re-adds survivors.
 *   - products.price/stock rollups are refreshed after every write.
 */
class VariantSyncService
{
    /** Maximum option axes per product (kept deliberately small). */
    public const MAX_OPTIONS = 2;

    public function sync(Product $product, array $options, array $variants, bool $variantsExist): void
    {
        DB::transaction(function () use ($product, $options, $variants, $variantsExist): void {
            if (! $variantsExist) {
                // Legacy single-SKU mode: exactly one Default variant carrying
                // the submitted product-level price/stock.
                $this->ensureSingleDefaultVariant($product);

                return;
            }

            $this->applyOptions($product, $options);
            $this->applyVariants($product, $variants);
        });

        $product->syncVariantRollups();
        $product->refresh();
    }

    /**
     * Keep exactly one variant for a legacy product and mirror the product's
     * submitted price/stock into it (the Default row IS the product's SKU).
     */
    private function ensureSingleDefaultVariant(Product $product): void
    {
        // Legacy single-SKU mode: every option variant is dropped (pivot rows
        // cascade away with them), then the submitted product-level price/
        // stock is mirrored into the one pivot-free Default row — which the
        // created-hook already made; older products get it made here.
        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereHas('optionValues')
            ->delete();

        $default = ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereDoesntHave('optionValues')
            ->orderBy('id')
            ->first();

        if ($default === null) {
            ProductVariant::query()->create([
                'product_id' => $product->id,
                'sku' => null,
                'price' => $product->price,
                'stock_quantity' => (int) $product->stock_quantity,
                'is_available' => true,
                'weight_grams' => $product->weight_grams,
                'dimensions' => $product->dimensions,
            ]);

            return;
        }

        // A legacy product may carry stale option rows if combinations were
        // removed on an earlier edit — drop them along with any extra rows.
        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereKeyNot($default->id)
            ->delete();

        $default->update([
            'price' => $product->price,
            'stock_quantity' => (int) $product->stock_quantity,
            'is_available' => true,
            'weight_grams' => $product->weight_grams,
            'dimensions' => $product->dimensions,
        ]);
    }

    /** Replace the product's option axes/values from the submitted payload. */
    private function applyOptions(Product $product, array $options): void
    {
        $options = array_slice(array_values($options), 0, self::MAX_OPTIONS);

        $product->options()->delete();

        foreach ($options as $index => $payload) {
            $name = trim((string) ($payload['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $values = array_values(array_unique(array_filter(
                array_map(fn ($v) => trim((string) $v), (array) ($payload['values'] ?? [])),
                fn (string $v): bool => $v !== '',
            )));

            if ($values === []) {
                continue;
            }

            $option = ProductOption::query()->create([
                'product_id' => $product->id,
                'name' => $name,
                'sort_order' => $index,
            ]);

            foreach ($values as $valueIndex => $value) {
                ProductOptionValue::query()->create([
                    'product_option_id' => $option->id,
                    'value' => $value,
                    'sort_order' => $valueIndex,
                ]);
            }
        }
    }

    /**
     * Regenerate variant rows for every Cartesian combination of the freshly
     * written options, applying the seller's per-row SKU/price/stock/flag and
     * matching rows back by their option-value signature.
     */
    private function applyVariants(Product $product, array $variants): void
    {
        // After applyOptions(), every surviving option has ≥1 value.
        $axes = $product->options()->with('values')->get()
            ->map(fn (ProductOption $option): array => [
                'name' => $option->name,
                'valueIds' => $option->values->pluck('id', 'value')->all(),
                'values' => $option->values->pluck('value')->all(),
            ])
            ->values()
            ->all();

        if ($axes === []) {
            // Options were cleared — fall back to legacy single-SKU mode:
            // drop option variants, keep exactly one Default row.
            ProductVariant::query()
                ->where('product_id', $product->id)
                ->whereHas('optionValues')
                ->delete();

            $default = $product->variants()->whereDoesntHave('optionValues')->first();

            if ($default === null) {
                $default = ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'sku' => null,
                    'price' => $product->price,
                    'stock_quantity' => (int) $product->stock_quantity,
                    'is_available' => true,
                    'weight_grams' => $product->weight_grams,
                    'dimensions' => $product->dimensions,
                ]);
            }

            // Mirror the freshly submitted product-level price/stock into the
            // surviving Default row (the caller updated products before sync).
            $default->update([
                'price' => $product->price,
                'stock_quantity' => (int) $product->stock_quantity,
                'is_available' => true,
                'weight_grams' => $product->weight_grams,
                'dimensions' => $product->dimensions,
            ]);

            return;
        }

        // Options exist → the auto-generated Default row is superseded by the
        // generated combinations and goes away (cart lines referencing it
        // cascade; buyers re-add the surviving combination).
        ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereDoesntHave('optionValues')
            ->delete();

        $combinations = $this->cartesian(array_column($axes, 'values'));

        // Submitted rows indexed by "Red|M" signature.
        $submitted = [];
        foreach ($variants as $row) {
            $signature = $this->signature((array) ($row['values'] ?? []));
            $submitted[$signature] = $row;
        }

        // Existing option variants indexed by the same signature, so an edit
        // keeps seller-entered SKU/price/stock stable across submissions.
        $existingBySignature = [];
        foreach ($product->variants()->with('optionValues')->get() as $existing) {
            if ($existing->hasOptions()) {
                $existingBySignature[$this->signature($existing->optionValues->pluck('value')->all())] = $existing;
            }
        }

        foreach ($combinations as $combination) {
            $signature = $this->signature($combination);
            $existing = $existingBySignature[$signature] ?? null;
            $row = $submitted[$signature] ?? [];

            $valueIds = [];
            foreach ($combination as $axisIndex => $value) {
                $valueIds[] = $axes[$axisIndex]['valueIds'][$value];
            }

            if ($existing !== null) {
                $existing->update([
                    'sku' => array_key_exists('sku', $row) ? $row['sku'] : $existing->sku,
                    'price' => array_key_exists('price', $row) ? $row['price'] : $existing->price,
                    'stock_quantity' => (int) ($row['stock_quantity'] ?? $existing->stock_quantity),
                    'is_available' => (bool) ($row['is_available'] ?? $existing->is_available),
                ]);
                $this->attachValues($existing, $valueIds);
                unset($existingBySignature[$signature]);

                continue;
            }

            $created = ProductVariant::query()->create([
                'product_id' => $product->id,
                'sku' => $row['sku'] ?? null,
                'price' => $row['price'] ?? $product->price,
                'stock_quantity' => (int) ($row['stock_quantity'] ?? 0),
                'is_available' => (bool) ($row['is_available'] ?? true),
                'weight_grams' => null,
                'dimensions' => null,
            ]);
            $this->attachValues($created, $valueIds);
        }

        // Combinations the seller dropped (removed a value, cleared an axis).
        foreach ($existingBySignature as $stale) {
            $stale->delete();
        }
    }

    private function attachValues(ProductVariant $variant, array $valueIds): void
    {
        DB::table('product_variant_option_values')->where('product_variant_id', $variant->id)->delete();

        foreach ($valueIds as $valueId) {
            DB::table('product_variant_option_values')->insert([
                'product_variant_id' => $variant->id,
                'product_option_value_id' => $valueId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Cartesian product of the option axes, e.g.
     * [["Red","Blue"], ["M","L"]] → [["Red","M"],["Red","L"],["Blue","M"],["Blue","L"]]
     *
     * @param  array<int, array<int, string>>  $axes
     * @return array<int, array<int, string>>
     */
    private function cartesian(array $axes): array
    {
        $result = [[]];

        foreach ($axes as $axis) {
            $append = [];

            foreach ($result as $combination) {
                foreach ($axis as $value) {
                    $append[] = [...$combination, $value];
                }
            }

            $result = $append;
        }

        return $result;
    }

    /** @param array<int, string> $values */
    private function signature(array $values): string
    {
        return implode('|', $values);
    }
}
