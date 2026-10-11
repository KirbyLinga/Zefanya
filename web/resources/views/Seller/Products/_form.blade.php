@php
    // ── Variant Options state ────────────────────────────────────────────
    // Server-rendered from old() (failed POST) or the product's persisted
    // options/variants (edit mode); JS regenerates the variant table on
    // demand. Values are comma-joined strings throughout.
    $pfOptions = old('options');
    if (! is_array($pfOptions)) {
        $pfOptions = $product->exists
            ? $product->options->map(fn ($o) => [
                'name' => $o->name,
                'values' => $o->values->pluck('value')->implode(','),
            ])->all()
            : [];
    }

    $pfVariants = old('variants');
    if (! is_array($pfVariants)) {
        $pfVariants = $product->exists
            ? $product->variants->filter->hasOptions()->map(fn ($v) => [
                'values' => $v->optionValues->pluck('value')->implode(','),
                'sku' => $v->sku,
                'price' => $v->price,
                'stock_quantity' => $v->stock_quantity,
                'is_available' => $v->is_available,
            ])->values()->all()
            : [];
    }

    $pfHasVariants = old('variants_exist', ($product->exists && $product->hasVariants()) ? '1' : '0');
@endphp
@csrf
@if($product->exists) @method('PUT') @endif
<input type="hidden" name="variants_exist" data-pf-variants-exist value="{{ $pfHasVariants }}">

@if ($errors->any())
    <div class="px-3 py-3 bg-[var(--pill-danger-bg)] border border-[var(--pill-danger-border)] rounded-sm font-sans text-[12.5px] text-[var(--status-danger)]">{{ $errors->first() }}</div>
@endif

{{-- ═══════════════ STEP 1 · BASIC INFO ═══════════════ --}}
<div data-pf-step="1">
    <div class="flex gap-4 max-sm:flex-col">
        <div class="flex flex-col flex-1">
            <label for="name" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Product name *</label>
            <input type="text" name="name" id="name" required value="{{ old('name', $product->name) }}" class="px-3 py-2.5 border border-[var(--border-input)] rounded-sm font-sans text-[13.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]">
            @error('name') <span class="font-sans text-[11.5px] text-[var(--status-danger)] mt-1.5">{{ $message }}</span> @enderror
        </div>
        <div class="flex flex-col flex-[0_0_180px] max-sm:flex-1">
            <label class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Category</label>
            <div class="flex items-center gap-2 px-3 py-2.5 bg-[var(--bg-soft)] border border-[var(--border-input)] rounded-sm text-[var(--text-label)] font-sans text-[13.5px]">
                <i data-lucide="lock" width="14" height="14" class="text-primary-800 flex-shrink-0"></i>
                <span>{{ $lockedCategory->name ?? 'Uncategorized' }}</span>
            </div>
            <span class="font-sans text-[10.5px] italic text-[var(--text-label)] mt-1.5">Set by your seller registration — not editable per product.</span>
        </div>
    </div>

    <div class="flex flex-col flex-1">
        <label for="description" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Description</label>
        <textarea name="description" id="description" rows="4" class="px-3 py-2.5 border border-[var(--border-input)] rounded-sm font-sans text-[13.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]">{{ old('description', $product->description) }}</textarea>
    </div>

    <div class="flex gap-4 max-sm:flex-col">
        <div class="flex flex-col flex-1">
            <label for="price" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Price (₱) *</label>
            <input type="number" name="price" id="price" step="0.01" min="0" required value="{{ old('price', $product->price) }}" class="px-3 py-2.5 border border-[var(--border-input)] rounded-sm font-sans text-[13.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]">
            <span class="font-sans text-[10.5px] italic text-[var(--text-label)] mt-1.5 hidden" data-pf-rollup-hint-price>Auto — cheapest variant</span>
            @error('price') <span class="font-sans text-[11.5px] text-[var(--status-danger)] mt-1.5">{{ $message }}</span> @enderror
        </div>
        <div class="flex flex-col flex-1">
            <label for="stock_quantity" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Stock quantity *</label>
            <input type="number" name="stock_quantity" id="stock_quantity" min="0" required value="{{ old('stock_quantity', $product->stock_quantity) }}" class="px-3 py-2.5 border border-[var(--border-input)] rounded-sm font-sans text-[13.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]">
            <span class="font-sans text-[10.5px] italic text-[var(--text-label)] mt-1.5 hidden" data-pf-rollup-hint-stock>Auto — all variants</span>
        </div>
        <div class="flex flex-col flex-1">
            <label for="low_stock_threshold" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Low-stock alert at</label>
            <input type="number" name="low_stock_threshold" id="low_stock_threshold" min="0" value="{{ old('low_stock_threshold', $product->low_stock_threshold) }}" class="px-3 py-2.5 border border-[var(--border-input)] rounded-sm font-sans text-[13.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]">
        </div>
    </div>

    <div class="flex flex-col flex-1">
        <label for="status" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Status *</label>
        <select name="status" id="status" required class="px-3 py-2.5 border border-[var(--border-input)] rounded-sm font-sans text-[13.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]">
            <option value="draft" {{ old('status', $product->status) === 'draft' ? 'selected' : '' }}>Draft (not visible to buyers)</option>
            <option value="active" {{ old('status', $product->status) === 'active' ? 'selected' : '' }}>Active</option>
            <option value="inactive" {{ old('status', $product->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
        </select>
    </div>

    <div class="flex flex-col flex-1">
        <label for="images" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Product photos {{ $product->exists ? '(add more)' : '*' }}</label>
        <div class="flex items-center gap-3">
            <label for="images" class="inline-flex items-center gap-2 h-10 px-4 bg-[var(--bg-surface)] border border-[var(--btn-accent)] rounded-sm text-[var(--btn-accent)] font-sans font-semibold text-[11.5px] cursor-pointer whitespace-nowrap hover:bg-[var(--btn-accent)] hover:text-[var(--btn-accent-contrast)] transition-colors duration-200">
                <i data-lucide="upload" width="16" height="16"></i>
                <span>Choose photos</span>
            </label>
            <input type="file" name="images[]" id="images" accept="image/*" multiple class="absolute w-px h-px overflow-hidden">
            <span class="font-sans text-[12.5px] text-[var(--text-label)]" id="spFileName">No files selected</span>
        </div>
        @error('images') <span class="font-sans text-[11.5px] text-[var(--status-danger)] mt-1.5">{{ $message }}</span> @enderror
        @error('images.*') <span class="font-sans text-[11.5px] text-[var(--status-danger)] mt-1.5">{{ $message }}</span> @enderror
    </div>

    <div class="flex justify-end">
        <button type="button" data-pf-next="2"
                class="inline-flex h-10 items-center justify-center gap-2 rounded-sm border-none bg-[var(--btn-primary-bg)] px-5 font-sans text-[12px] font-semibold uppercase tracking-[0.8px] text-[var(--btn-primary-text)] cursor-pointer transition-colors hover:bg-[var(--btn-primary-bg-hover-soft)]">
            Next: Variant Options
            <i data-lucide="arrow-right" width="14" height="14"></i>
        </button>
    </div>
</div>

{{-- ═══════════════ STEP 2 · VARIANT OPTIONS ═══════════════ --}}
<div data-pf-step="2" {{ $pfHasVariants === '1' ? '' : 'hidden' }}>
    <p class="font-sans text-[12.5px] text-[var(--text-label)]">
        Add up to two option axes (e.g. Color, Size). Leave empty to sell this product as a single item.
    </p>

    <div data-pf-options class="flex flex-col gap-4">
        @forelse ($pfOptions as $i => $option)
            <div class="flex gap-4 max-sm:flex-col" data-pf-option-row>
                <div class="flex flex-col flex-1">
                    <label class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Option {{ $i + 1 }} name</label>
                    <input type="text" name="options[{{ $i }}][name]" data-pf-option-name maxlength="50" value="{{ $option['name'] ?? '' }}" placeholder="e.g. Color" class="px-3 py-2.5 border border-[var(--border-input)] rounded-sm font-sans text-[13.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]">
                </div>
                <div class="flex flex-col flex-[2]">
                    <label class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Values (comma-separated)</label>
                    <input type="text" name="options[{{ $i }}][values]" data-pf-option-values value="{{ $option['values'] ?? '' }}" placeholder="e.g. Red, Blue, Green" class="px-3 py-2.5 border border-[var(--border-input)] rounded-sm font-sans text-[13.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]">
                </div>
            </div>
        @empty
            <div class="flex gap-4 max-sm:flex-col" data-pf-option-row>
                <div class="flex flex-col flex-1">
                    <label class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Option 1 name</label>
                    <input type="text" name="options[0][name]" data-pf-option-name maxlength="50" value="" placeholder="e.g. Color" class="px-3 py-2.5 border border-[var(--border-input)] rounded-sm font-sans text-[13.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]">
                </div>
                <div class="flex flex-col flex-[2]">
                    <label class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[var(--text-label)] mb-2">Values (comma-separated)</label>
                    <input type="text" name="options[0][values]" data-pf-option-values value="" placeholder="e.g. Red, Blue, Green" class="px-3 py-2.5 border border-[var(--border-input)] rounded-sm font-sans text-[13.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]">
                </div>
            </div>
        @endforelse
    </div>

    <button type="button" data-pf-add-option
            class="mt-3 inline-flex items-center gap-1.5 font-sans text-[12px] font-semibold text-[var(--link)] cursor-pointer bg-transparent border-none p-0 hover:underline">
        <i data-lucide="plus" width="14" height="14"></i>
        Add second option
    </button>

    <p class="mt-3 font-sans text-[12.5px] text-[var(--text-label)]" data-pf-combo-summary hidden></p>
    <p class="mt-2 font-sans text-[11.5px] text-[var(--status-danger)]" data-pf-options-error hidden></p>

    <div class="flex justify-between mt-2">
        <button type="button" data-pf-back="1"
                class="inline-flex h-10 items-center justify-center rounded-sm border border-[var(--border-cancel)] bg-[var(--bg-surface)] px-5 font-sans text-[12px] font-semibold uppercase tracking-[0.8px] text-[var(--btn-cancel-text)] cursor-pointer transition-colors hover:bg-[var(--bg-raised)]">
            Back
        </button>
        <button type="button" data-pf-generate
                class="inline-flex h-10 items-center justify-center gap-2 rounded-sm border-none bg-[var(--btn-primary-bg)] px-5 font-sans text-[12px] font-semibold uppercase tracking-[0.8px] text-[var(--btn-primary-text)] cursor-pointer transition-colors hover:bg-[var(--btn-primary-bg-hover-soft)]">
            <span data-pf-generate-label>Save product</span>
            <i data-lucide="arrow-right" width="14" height="14"></i>
        </button>
    </div>
</div>

{{-- ═══════════════ STEP 3 · VARIANT TABLE ═══════════════ --}}
<div data-pf-step="3" hidden>
    <p class="font-sans text-[12.5px] text-[var(--text-label)]">
        One row per combination. Untick a row to disable that combination without deleting it.
    </p>

    <div class="overflow-x-auto mt-2">
        <table class="w-full border-collapse font-sans text-[12.5px]">
            <thead>
                <tr class="text-left text-[var(--text-label)] border-b border-[var(--border-input)]">
                    <th class="py-2 pr-3 font-semibold">Variant</th>
                    <th class="py-2 pr-3 font-semibold">SKU</th>
                    <th class="py-2 pr-3 font-semibold">Price (₱)</th>
                    <th class="py-2 pr-3 font-semibold">Stock</th>
                    <th class="py-2 font-semibold">Selling</th>
                </tr>
            </thead>
            <tbody data-pf-variant-body>
                @foreach ($pfVariants as $i => $row)
                    <tr data-pf-variant-row>
                        <input type="hidden" name="variants[{{ $i }}][values]" value="{{ $row['values'] }}">
                        <input type="hidden" name="variants[{{ $i }}][is_available]" value="{{ ($row['is_available'] ?? true) ? '1' : '0' }}" data-pf-available>
                        <td class="py-2 pr-3 font-semibold text-[var(--text-ink)]" data-pf-combo-label>{{ str_replace(',', ' / ', $row['values']) }}</td>
                        <td class="py-2 pr-3"><input type="text" name="variants[{{ $i }}][sku]" maxlength="64" value="{{ $row['sku'] ?? '' }}" class="w-28 px-2 py-1.5 border border-[var(--border-input)] rounded-sm text-[12.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]"></td>
                        <td class="py-2 pr-3"><input type="number" step="0.01" min="0" name="variants[{{ $i }}][price]" value="{{ $row['price'] ?? '' }}" required class="w-24 px-2 py-1.5 border border-[var(--border-input)] rounded-sm text-[12.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]"></td>
                        <td class="py-2 pr-3"><input type="number" min="0" name="variants[{{ $i }}][stock_quantity]" value="{{ $row['stock_quantity'] ?? 0 }}" required class="w-20 px-2 py-1.5 border border-[var(--border-input)] rounded-sm text-[12.5px] text-[var(--text-ink)] focus:outline-none focus:border-[var(--brand)]"></td>
                        <td class="py-2"><input type="checkbox" data-pf-enabled {{ ($row['is_available'] ?? true) ? 'checked' : '' }} class="cursor-pointer" aria-label="Selling this combination"></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="font-sans text-[11.5px] text-[var(--status-danger)] mt-2" data-pf-variant-empty hidden></p>
    </div>

    <div class="flex justify-between mt-4">
        <button type="button" data-pf-back="2"
                class="inline-flex h-10 items-center justify-center rounded-sm border border-[var(--border-cancel)] bg-[var(--bg-surface)] px-5 font-sans text-[12px] font-semibold uppercase tracking-[0.8px] text-[var(--btn-cancel-text)] cursor-pointer transition-colors hover:bg-[var(--bg-raised)]">
            Back to options
        </button>
    </div>
</div>
