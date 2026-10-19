@php
    $pfOptions = old('options');
    if (! is_array($pfOptions)) {
        $pfOptions = $product->exists ? $product->options->map(fn ($option) => ['name' => $option->name, 'values' => $option->values->pluck('value')->all()])->all() : [];
    }
    $pfVariants = old('variants');
    if (! is_array($pfVariants)) {
        $pfVariants = $product->exists ? $product->variants->filter->hasOptions()->map(fn ($variant) => ['id' => $variant->id, 'values' => $variant->optionValues->pluck('value')->all(), 'sku' => $variant->sku, 'price' => $variant->price, 'stock_quantity' => $variant->stock_quantity, 'is_available' => $variant->is_available])->values()->all() : [];
    }
    $pfColorImages = [];
    $pfColorImageIds = [];
    if ($product->exists) {
        $colorOption = $product->options->first(fn ($option) => in_array(strtolower(trim($option->name)), ['color', 'colour']));
        if ($colorOption) {
            foreach ($product->variants as $variant) {
                $color = $variant->optionValues->firstWhere('product_option_id', $colorOption->id)?->value;
                if ($color) {
                    foreach ($product->images->where('variant_id', $variant->id) as $image) {
                        $pfColorImageIds[$color][Storage::disk('public')->url($image->path)] = $image->id;
                    }
                    $pfColorImages[$color] = array_values(array_unique(array_merge($pfColorImages[$color] ?? [], $product->images->where('variant_id', $variant->id)->map(fn ($image) => Storage::disk('public')->url($image->path))->all())));
                }
            }
        }
    }
    $pfColorFirst = (bool) old('color_first', ! $product->exists || $pfOptions === [] || ($pfOptions !== [] && count($pfOptions) === 2 && in_array(strtolower($pfOptions[0]['name']), ['color', 'colour']) && strtolower($pfOptions[1]['name']) === 'size'));
    $pfInitial = ['options' => $pfOptions, 'variants' => $pfVariants, 'colorImages' => $pfColorImages, 'colorImageIds' => $pfColorImageIds];
    $pfHasVariants = (string) old('variants_exist', ($product->exists && $product->hasVariants()) ? '1' : '0');
    $inputClass = 'w-full rounded-sm border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2.5 text-sm text-[var(--text-ink)] focus:border-[var(--brand)] focus:outline-none';
    $buttonClass = 'rounded-sm border border-[var(--border-input)] px-3 py-2 text-xs font-semibold text-[var(--text-ink)] transition-colors hover:bg-[var(--bg-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]';
@endphp
@csrf
@if($product->exists)
    @method('PUT')
    <input type="hidden" name="inventory_revision" value="{{ old('inventory_revision', app(\App\Services\ProductPublishingService::class)->inventoryRevision($product)) }}">
@endif
<div data-pf-root data-pf-color-first="{{ $pfColorFirst ? '1' : '0' }}" data-pf-edit="{{ $product->exists ? '1' : '0' }}" class="flex flex-col gap-6 font-sans text-sm text-[var(--text-ink)]">
    <script type="application/json" data-pf-initial>@json($pfInitial)</script>
    <input type="hidden" name="color_first" value="{{ $pfColorFirst ? '1' : '0' }}">
    <input type="hidden" name="variants_exist" data-pf-variants-exist value="{{ $pfHasVariants }}">
    <input type="hidden" name="cover_index" data-pf-cover-index value="0" @disabled($product->exists)>
    @if($errors->any())
        <div data-pf-error-summary role="alert" class="rounded-sm border border-[var(--pill-danger-border)] bg-[var(--pill-danger-bg)] p-4 text-[var(--status-danger)]">
            <strong>Please check your product details.</strong>
            <ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            <p class="mt-2">For your privacy, please select any new photo uploads again.</p>
        </div>
    @endif
    <ol aria-label="Product setup progress" class="grid grid-cols-5 gap-1 border-b border-[var(--border-hairline)] pb-5">
        @foreach(['Information', 'Variations', 'Color images', 'Inventory', 'Publish'] as $label)
            <li data-pf-progress="{{ $loop->iteration }}" class="flex flex-col gap-2 text-center text-[10px] text-[var(--text-muted)] sm:text-xs">
                <span data-pf-number class="text-[var(--text-ink)] mx-auto flex size-7 items-center justify-center rounded-full border border-[var(--border-input)] text-xs">{{ $loop->iteration }}</span>{{ $label }}
            </li>
        @endforeach
    </ol>
    <p data-pf-error role="alert" hidden class="rounded-sm bg-[var(--pill-danger-bg)] p-3 text-[var(--status-danger)]"></p>
    <section data-pf-step="1" class="space-y-5">
        <div><p class="text-xs uppercase tracking-widest text-[var(--text-muted)]">Step 01 / Product information</p><h3 class="text-[var(--text-ink)] mt-1 font-serif text-2xl">Start with the essentials</h3><p class="mt-2 text-[var(--text-muted)]">One product listing. Every color and size in one place.</p></div>
        <div class="grid gap-5 sm:grid-cols-[1fr_230px]">
            <label class="text-[var(--text-ink)] flex flex-col gap-2 font-semibold" for="name">Product name <span class="text-[var(--text-ink)] sr-only">required</span><input id="name" name="name" maxlength="255" required value="{{ old('name', $product->name) }}" class="{{ $inputClass }}" placeholder="e.g. Premium cotton oversized shirt"></label>
            <div class="flex flex-col gap-2"><span class="text-[var(--text-ink)] font-semibold">Category</span><p data-pf-category class="rounded-sm border border-[var(--border-input)] bg-[var(--bg-soft)] px-3 py-2.5">{{ $lockedCategory->label ?? 'Uncategorized' }}</p><p class="text-xs text-[var(--text-muted)]">Set by your seller registration.</p></div>
        </div>
        <label for="description" class="text-[var(--text-ink)] flex flex-col gap-2 font-semibold">Product description<textarea id="description" name="description" rows="4" maxlength="5000" class="{{ $inputClass }}" placeholder="Premium cotton oversized shirt, available in multiple colors and sizes.">{{ old('description', $product->description) }}</textarea></label>
        <div class="space-y-3"><div><h4 class="text-[var(--text-ink)] font-semibold">Product images</h4><p class="mt-1 text-xs text-[var(--text-muted)]">Add up to 6 JPG, PNG or WebP photos, 3 MB each. Choose the cover buyers see first.</p></div>
            @if($product->exists)
                <div class="flex flex-wrap gap-3" data-pf-existing-images>
                    @foreach($product->images->whereNull('variant_id') as $image)<figure class="w-20"><img src="{{ Storage::disk('public')->url($image->path) }}" alt="Existing product photo" class="aspect-square w-full rounded-sm border border-[var(--border-input)] object-cover">@if($image->is_primary)<figcaption class="mt-1 text-xs text-[var(--text-muted)]">Current cover</figcaption>@endif</figure>@endforeach
                </div>
            @endif
            <label for="images" class="text-[var(--text-ink)] flex cursor-pointer flex-col items-center gap-2 rounded-sm border border-dashed border-[var(--border-input)] bg-[var(--bg-soft)] p-6 text-center hover:border-[var(--brand)]"><span class="text-[var(--text-ink)] font-semibold">+ Choose product photos</span><span class="text-xs text-[var(--text-muted)]">{{ $product->exists ? 'New photos are added to your gallery. Choose a new cover only if needed.' : 'The first selected photo becomes your cover.' }}</span><input id="images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-pf-gallery-files class="max-w-full text-xs"></label>
            <div data-pf-gallery class="grid grid-cols-2 gap-3 sm:grid-cols-6"></div>
        </div>
    </section>
    <section data-pf-step="2" hidden class="space-y-5">
        <div><p class="text-xs uppercase tracking-widest text-[var(--text-muted)]">Step 02 / Product variations</p><h3 class="text-[var(--text-ink)] mt-1 font-serif text-2xl">Give buyers their options</h3><p class="mt-2 text-[var(--text-muted)]">Add each color with its own uploaded photo and available sizes. Existing option groups remain editable.</p></div>
        <label class="text-[var(--text-ink)] flex items-center gap-3 rounded-sm border border-[var(--border-input)] bg-[var(--bg-soft)] p-4 font-semibold"><input type="checkbox" data-pf-variants-toggle @checked($pfHasVariants === '1') class="size-4 accent-[var(--brand)]">Enable Variations</label>
        <div data-pf-options-area class="space-y-4">
            @if($pfColorFirst)<h4 class="font-semibold">Color Variants</h4><button type="button" data-pf-add-color class="{{ $buttonClass }}">+ Add Another Color</button><div data-pf-colors class="space-y-4"></div>@endif<div data-pf-options class="space-y-4"></div><button type="button" data-pf-add-option @if($pfColorFirst) hidden @endif class="{{ $buttonClass }}">+ Add variation group</button><p data-pf-combo-summary class="font-semibold text-[var(--text-label)]" aria-live="polite"></p></div>
        <p data-pf-single-message class="text-[var(--text-muted)]">Selling a single item? Continue to enter its price and stock.</p>
    </section>
    <section data-pf-step="3" hidden class="space-y-5">
        <div><p class="text-xs uppercase tracking-widest text-[var(--text-muted)]">Step 03 / Color images</p><h3 class="text-[var(--text-ink)] mt-1 font-serif text-2xl">Show every color</h3><p class="mt-2 text-[var(--text-muted)]">Each color needs an explicitly uploaded photo. Return to Variations to add or replace color photos; all sizes share the same color image.</p></div>
        <p data-pf-no-colors class="rounded-sm bg-[var(--bg-soft)] p-4 text-[var(--text-muted)]">Add a Color or Colour variation to upload photos by color. General product photos will otherwise appear for every variant.</p>
        @unless($pfColorFirst)<div data-pf-colors class="grid gap-4 sm:grid-cols-3"></div>@endunless
    </section>
    <section data-pf-step="4" hidden class="space-y-5">
        <div><p class="text-xs uppercase tracking-widest text-[var(--text-muted)]">Step 04 / Variant inventory</p><h3 class="text-[var(--text-ink)] mt-1 font-serif text-2xl">Set price and stock</h3><p class="mt-2 text-[var(--text-muted)]">Each combination has its own inventory. Uncheck Selling to pause a variant.</p></div>
        <div data-pf-single-inventory class="grid gap-4 sm:grid-cols-2">
            <label for="price" class="text-[var(--text-ink)] flex flex-col gap-2 font-semibold">Price (₱)<input name="price" id="price" type="number" step="0.01" min="0" required value="{{ old('price', $product->price ?? 0) }}" class="{{ $inputClass }}"></label>
            <label for="stock_quantity" class="text-[var(--text-ink)] flex flex-col gap-2 font-semibold">Stock<input name="stock_quantity" id="stock_quantity" type="number" step="1" min="0" required value="{{ old('stock_quantity', $product->stock_quantity ?? 0) }}" class="{{ $inputClass }}"></label>
        </div>
        <div data-pf-variant-inventory class="space-y-4">
            <div class="flex flex-wrap items-end gap-3 rounded-sm bg-[var(--bg-soft)] p-3"><label class="text-[var(--text-ink)] flex flex-col gap-1 text-xs">Bulk price (₱)<input type="number" min="0" step="0.01" data-pf-bulk-price class="{{ $inputClass }}" placeholder="0.00"></label><label class="text-[var(--text-ink)] flex flex-col gap-1 text-xs">Bulk stock<input type="number" min="0" step="1" data-pf-bulk-stock class="{{ $inputClass }}" placeholder="0"></label><button type="button" data-pf-apply-bulk class="{{ $buttonClass }}">Apply to all variants</button></div>
            <p class="text-xs text-[var(--text-muted)] sm:hidden">Swipe horizontally to see price, stock, SKU and Selling. Variant names stay in view.</p><div class="max-h-[45vh] overflow-auto rounded-sm border border-[var(--border-input)]"><table class="w-full min-w-[650px] text-left text-sm"><thead class="sticky top-0 z-20 bg-[var(--bg-soft)] text-xs text-[var(--text-label)]"><tr><th class="sticky left-0 z-30 bg-[var(--bg-soft)] p-3 text-[var(--text-ink)]">Variant</th><th class="p-3">Price (₱)</th><th class="p-3">Stock</th><th class="p-3">SKU (optional)</th><th class="p-3">Selling</th></tr></thead><tbody data-pf-variant-body></tbody></table></div>
        </div>
        <div class="flex items-baseline justify-between border-y border-[var(--border-hairline)] py-4"><span class="text-[var(--text-muted)]">Total stock</span><span class="text-[var(--text-ink)] font-serif text-2xl"><strong data-pf-total-stock class="text-[var(--text-ink)]">0</strong> <span class="text-[var(--text-ink)] font-sans text-sm">pieces</span></span></div>
        <label for="low_stock_threshold" class="text-[var(--text-ink)] flex max-w-xs flex-col gap-2 font-semibold">Low-stock alert at<input name="low_stock_threshold" id="low_stock_threshold" type="number" step="1" min="0" value="{{ old('low_stock_threshold', $product->low_stock_threshold ?? 5) }}" class="{{ $inputClass }}"></label>
    </section>
    <section data-pf-step="5" hidden class="space-y-5">
        <div><p class="text-xs uppercase tracking-widest text-[var(--text-muted)]">Step 05 / Review & publish</p><h3 class="text-[var(--text-ink)] mt-1 font-serif text-2xl">Ready for your storefront</h3><p class="mt-2 text-[var(--text-muted)]">Review the details before making this product available to buyers.</p></div>
        <div class="flex gap-5 rounded-sm border border-[var(--border-input)] bg-[var(--bg-soft)] p-5"><img data-pf-review-cover hidden alt="Selected product cover" class="size-24 shrink-0 rounded-sm object-cover"><div class="min-w-0 space-y-2"><h4 data-pf-review-name class="text-[var(--text-ink)] break-words font-serif text-xl"></h4><p data-pf-review-category class="text-xs text-[var(--text-muted)]"></p><p data-pf-review-options class="text-xs text-[var(--text-ink)]"></p><p data-pf-review-summary class="font-semibold text-[var(--text-ink)]"></p></div></div>
        <label for="status" class="text-[var(--text-ink)] flex flex-col gap-2 font-semibold">Publication status<select id="status" name="status" class="{{ $inputClass }}"><option value="active" @selected(old('status', $product->exists ? $product->status : 'active') === 'active')>Publish — visible to buyers</option><option value="draft" @selected(old('status', $product->exists ? $product->status : 'active') === 'draft')>Save as draft — only visible to you</option><option value="inactive" @selected(old('status', $product->status) === 'inactive')>Inactive — hidden from buyers</option></select></label>
        <p class="text-xs text-[var(--text-muted)]">Buyers see one listing and choose the available variation before purchasing.</p>
    </section>
</div>
