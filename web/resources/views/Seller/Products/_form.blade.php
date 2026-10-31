@php
    $pfOptions = old('options');
    if (! is_array($pfOptions)) {
        $pfOptions = $product->exists ? $product->options->map(fn ($option) => ['name' => $option->name, 'values' => $option->values->pluck('value')->all()])->all() : [];
    }
    $pfVariants = old('variants');
    if (! is_array($pfVariants)) {
        $pfVariants = $product->exists ? $product->variants->filter->hasOptions()->map(fn ($variant) => ['id' => $variant->id, 'values' => $variant->optionValues->pluck('value')->all(), 'weight' => $variant->weight_grams, 'weight_unit' => 'g', 'sku' => $variant->sku, 'price' => $variant->price, 'stock_quantity' => $variant->stock_quantity, 'is_available' => $variant->is_available])->values()->all() : [];
    }
    $pfColorImages = [];
    $pfColorImageIds = [];
    // Same-origin paths only — Storage::url() embeds APP_URL (often a local
    // http://localhost host), which browsers block as mixed content on HTTPS.
    $pfPublicUrl = static fn (string $path): string => config('filesystems.disks.public.driver') === 'local'
        ? '/storage/'.ltrim($path, '/')
        : Storage::disk('public')->url($path);
    if ($product->exists) {
        $colorOption = $product->options->first(fn ($option) => in_array(strtolower(trim($option->name)), ['color', 'colour']));
        if ($colorOption) {
            foreach ($product->variants as $variant) {
                $color = $variant->optionValues->firstWhere('product_option_id', $colorOption->id)?->value;
                if ($color) {
                    foreach ($product->images->where('variant_id', $variant->id) as $image) {
                        $pfColorImageIds[$color][$pfPublicUrl($image->path)] = $image->id;
                    }
                    $pfColorImages[$color] = array_values(array_unique(array_merge($pfColorImages[$color] ?? [], $product->images->where('variant_id', $variant->id)->map(fn ($image) => $pfPublicUrl($image->path))->all())));
                }
            }
        }
    }
    $pfColorFirst = (bool) old('color_first', ! $product->exists || $pfOptions === [] || ($pfOptions !== [] && count($pfOptions) === 2 && in_array(strtolower($pfOptions[0]['name']), ['color', 'colour']) && strtolower($pfOptions[1]['name']) === 'size'));
    $pfInitial = ['options' => $pfOptions, 'variants' => $pfVariants, 'colorImages' => $pfColorImages, 'colorImageIds' => $pfColorImageIds];
    $pfHasVariants = (string) old('variants_exist', ($product->exists && $product->hasVariants()) ? '1' : '0');
    $pfStoreCategoryIds = old('store_category_ids', $product->exists ? $product->storeCategories->modelKeys() : []);
    $pfStoreCategoryIds = is_array($pfStoreCategoryIds) ? $pfStoreCategoryIds : [];
    // A product's main category comes from its store and never changes: see StoreProductRequest.
    $pfMainCategory = $product->exists && $product->category_id ? $product->category : $lockedCategory;
    $pfCreationKey = old('creation_key');
    $pfCreationKey = is_string($pfCreationKey) && \Illuminate\Support\Str::isUuid($pfCreationKey) ? $pfCreationKey : (string) \Illuminate\Support\Str::uuid();
    $inputClass = 'w-full rounded-sm border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2.5 text-sm text-[var(--text-ink)] focus:border-[var(--brand)] focus:outline-none';
    $buttonClass = 'rounded-sm border border-[var(--border-input)] px-3 py-2 text-xs font-semibold text-[var(--text-ink)] transition-colors hover:bg-[var(--bg-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]';
@endphp
@csrf
@if(! $product->exists)<input type="hidden" name="creation_key" value="{{ $pfCreationKey }}">@endif
@if($product->exists)
    @method('PUT')
    <input type="hidden" name="inventory_revision" value="{{ old('inventory_revision', app(\App\Services\Seller\ProductPublishingService::class)->inventoryRevision($product)) }}">
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
    <ol data-pf-progress-list class="sticky -top-6 z-40 -mt-6 flex flex-wrap gap-x-5 gap-y-1 border-b border-[var(--border-hairline)] bg-[var(--bg-surface)] py-3">
        <li><button type="button" data-pf-progress="1" class="group flex min-h-9 cursor-pointer items-center gap-2 text-[13px] font-medium text-[var(--text-muted)] disabled:cursor-default aria-[current=step]:font-semibold aria-[current=step]:text-[var(--text-ink)]"><span data-pf-number class="flex size-6 items-center justify-center rounded-full border border-[var(--border-input)] text-xs group-aria-[current=step]:border-[var(--brand)] group-aria-[current=step]:bg-[var(--brand)] group-aria-[current=step]:text-white">1</span>Details</button></li>
        <li><button type="button" data-pf-progress="2" class="group flex min-h-9 cursor-pointer items-center gap-2 text-[13px] font-medium text-[var(--text-muted)] disabled:cursor-default aria-[current=step]:font-semibold aria-[current=step]:text-[var(--text-ink)]"><span data-pf-number class="flex size-6 items-center justify-center rounded-full border border-[var(--border-input)] text-xs group-aria-[current=step]:border-[var(--brand)] group-aria-[current=step]:bg-[var(--brand)] group-aria-[current=step]:text-white">2</span>Photos and versions</button></li>
        <li><button type="button" data-pf-progress="3" class="group flex min-h-9 cursor-pointer items-center gap-2 text-[13px] font-medium text-[var(--text-muted)] disabled:cursor-default aria-[current=step]:font-semibold aria-[current=step]:text-[var(--text-ink)]"><span data-pf-number class="flex size-6 items-center justify-center rounded-full border border-[var(--border-input)] text-xs group-aria-[current=step]:border-[var(--brand)] group-aria-[current=step]:bg-[var(--brand)] group-aria-[current=step]:text-white">3</span>Price and stock</button></li>
        <li><button type="button" data-pf-progress="4" class="group flex min-h-9 cursor-pointer items-center gap-2 text-[13px] font-medium text-[var(--text-muted)] disabled:cursor-default aria-[current=step]:font-semibold aria-[current=step]:text-[var(--text-ink)]"><span data-pf-number class="flex size-6 items-center justify-center rounded-full border border-[var(--border-input)] text-xs group-aria-[current=step]:border-[var(--brand)] group-aria-[current=step]:bg-[var(--brand)] group-aria-[current=step]:text-white">4</span>Review</button></li>
    </ol>
    <p data-pf-error role="alert" hidden class="rounded-sm bg-[var(--pill-danger-bg)] p-3 text-[var(--status-danger)]"></p>
    <section data-pf-step="1" class="space-y-5">
        <h3 class="font-serif text-xl text-[var(--text-ink)]">What are you selling?</h3>
            <label class="text-[var(--text-ink)] flex flex-col gap-2 font-semibold" for="name">Product name <span class="text-[var(--text-ink)] sr-only">required</span><input id="name" name="name" minlength="5" maxlength="120" required value="{{ old('name', $product->name) }}" class="{{ $inputClass }}" placeholder="e.g. Premium cotton oversized shirt"></label>
        <div class="grid gap-5 sm:grid-cols-[230px_1fr]">
            @if($pfMainCategory)
                <div class="flex flex-col gap-2">
                    <span class="font-semibold">Main category</span>
                    <p class="flex items-center gap-2 rounded-sm border border-[var(--border-input)] bg-[var(--bg-soft)] px-3 py-2.5 text-sm text-[var(--text-ink)]"><i data-lucide="lock" class="size-3.5 shrink-0 text-[var(--text-muted)]" aria-hidden="true"></i><span data-pf-category class="text-[var(--text-ink)]">{{ $pfMainCategory->label }}</span></p>
                    <p class="text-xs text-[var(--text-muted)]">Set by your store. It can't be changed.</p>
                </div>
            @else
                <label class="flex flex-col gap-2 font-semibold">Main category<select name="category_id" data-pf-category required class="{{ $inputClass }}"><option value="">Choose a category</option>@foreach($platformCategories as $category)<option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->label }}</option>@endforeach</select></label>
            @endif
            <input type="hidden" name="store_categories_present" value="1">
            <fieldset data-pf-store-categories class="flex min-w-0 flex-col gap-2">
                <legend class="mb-2 font-semibold">Sub-category <span class="font-normal text-[var(--text-muted)]">(optional, up to three)</span></legend>
                @if($storeCategories->isNotEmpty())
                    <div class="flex flex-wrap gap-2">@foreach($storeCategories as $storeCategory)<label class="flex cursor-pointer items-center gap-2 rounded-sm border border-[var(--border-input)] px-3 py-2"><input type="checkbox" name="store_category_ids[]" value="{{ $storeCategory->id }}" @checked(in_array($storeCategory->id, $pfStoreCategoryIds)) class="size-4 accent-[var(--brand)]">{{ $storeCategory->name }}</label>@endforeach</div>
                @endif
                <input name="new_store_category" data-pf-new-store-category maxlength="50" value="{{ old('new_store_category') }}" aria-label="Add a new sub-category" placeholder="{{ $pfMainCategory ? 'Add new, e.g. a type of '.mb_strtolower($pfMainCategory->label) : 'Add a new sub-category' }}" class="{{ $inputClass }}">
                <p data-pf-category-count class="text-xs text-[var(--text-muted)]" aria-live="polite"></p>
            </fieldset>
        </div>
        <label for="description" class="text-[var(--text-ink)] flex flex-col gap-2 font-semibold">Product description<textarea id="description" name="description" rows="4" minlength="20" maxlength="3000" required class="{{ $inputClass }}" placeholder="Premium cotton oversized shirt, available in multiple colors and sizes.">{{ old('description', $product->description) }}</textarea></label>
    </section>
    <section data-pf-step="2" hidden class="space-y-6">
        <h3 class="font-serif text-xl text-[var(--text-ink)]">Photos and versions</h3>
        <div class="space-y-3"><div><h3 class="font-semibold text-[var(--text-ink)]">Main photos</h3><p class="mt-1 text-xs text-[var(--text-muted)]">Buyers see these first. Add up to 9 JPG or PNG photos, 5 MB each.</p></div>
            @if($product->exists)
                <div class="flex flex-wrap gap-3" data-pf-existing-images>
                    @foreach($product->images->whereNull('variant_id') as $image)<figure class="w-20"><img src="{{ $pfPublicUrl($image->path) }}" alt="Existing product photo" class="aspect-square w-full rounded-sm border border-[var(--border-input)] object-cover">@if($image->is_primary)<figcaption class="mt-1 text-xs text-[var(--text-muted)]">Current cover</figcaption>@endif</figure>@endforeach
                </div>
            @endif
            <label for="images" class="text-[var(--text-ink)] flex cursor-pointer flex-col items-center gap-2 rounded-sm border border-dashed border-[var(--border-input)] bg-[var(--bg-soft)] p-4 text-center hover:border-[var(--brand)]"><span class="text-[var(--text-ink)] font-semibold">+ Choose product photos</span><span class="text-xs text-[var(--text-muted)]">{{ $product->exists ? 'New photos are added to your gallery. Choose a new cover only if needed.' : 'The first selected photo becomes your cover.' }}</span><input id="images" name="images[]" type="file" accept="image/jpeg,image/png" multiple data-pf-gallery-files class="sr-only"></label>
            <div data-pf-gallery class="grid grid-cols-2 gap-3 sm:grid-cols-6"></div>
        </div>
        <fieldset class="space-y-3">
            <legend class="mb-3 font-semibold text-[var(--text-ink)]">Does this product come in different versions?</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-[var(--border-input)] p-4 transition-colors hover:border-[var(--brand)] has-[:checked]:border-[var(--brand)] has-[:checked]:bg-[var(--bg-soft)]">
                    <input type="radio" name="pf_versions" value="one" data-pf-variants-none @checked($pfHasVariants !== '1') class="mt-0.5 size-4 shrink-0 accent-[var(--brand)]">
                    <span><span class="block font-semibold text-[var(--text-ink)]">No, just one version</span><span class="mt-1 block text-xs text-[var(--text-muted)]">One look, one price. Example: a tumbler in a single color.</span></span>
                </label>
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-[var(--border-input)] p-4 transition-colors hover:border-[var(--brand)] has-[:checked]:border-[var(--brand)] has-[:checked]:bg-[var(--bg-soft)]">
                    <input type="radio" name="pf_versions" value="many" data-pf-variants-toggle @checked($pfHasVariants === '1') class="mt-0.5 size-4 shrink-0 accent-[var(--brand)]">
                    <span><span class="block font-semibold text-[var(--text-ink)]">Yes, different colors or sizes</span><span class="mt-1 block text-xs text-[var(--text-muted)]">Buyers choose before they buy. Example: a shirt in Black and White, sizes S to L.</span></span>
                </label>
            </div>
        </fieldset>
        <div data-pf-options-area class="space-y-4">
            <div class="rounded-lg bg-[var(--bg-soft)] p-4 text-[13px] text-[var(--text-ink)]">
                <p class="font-semibold text-[var(--text-ink)]">How buyers see this</p>
                <p class="mt-1 text-[var(--text-muted)]">It stays one product page. Buyers open it, pick a color and size, and the photos change to the color they picked.</p>
            </div>
            @if($pfColorFirst)<div data-pf-colors class="space-y-4"></div><button type="button" data-pf-add-color class="{{ $buttonClass }}">+ Add Another Color</button>@endif<div data-pf-options class="space-y-4"></div><button type="button" data-pf-add-option @if($pfColorFirst) hidden @endif class="{{ $buttonClass }}">+ Add variation group</button><p data-pf-combo-summary class="font-semibold text-[var(--text-label)]" aria-live="polite"></p></div>
        <div data-pf-color-photos class="space-y-4">
            <h4 class="font-semibold text-[var(--text-ink)]">Color photos</h4>
            <p data-pf-no-colors class="rounded-sm bg-[var(--bg-soft)] p-4 text-[var(--text-muted)]">Add a Color or Colour variation to upload photos by color. General product photos will otherwise appear for every variant.</p>
            @unless($pfColorFirst)<div data-pf-colors class="grid gap-4 sm:grid-cols-3"></div>@endunless
        </div>
    </section>
    <section data-pf-step="3" hidden class="space-y-5">
        <h3 class="font-serif text-xl text-[var(--text-ink)]">Price, stock and weight</h3>
        <div data-pf-single-inventory class="grid gap-4 sm:grid-cols-2">
            <label for="price" class="text-[var(--text-ink)] flex flex-col gap-2 font-semibold">Price (₱)<input name="price" max="1000000" id="price" type="number" step="0.01" min="1" required value="{{ old('price', $product->price ?? '') }}" placeholder="e.g. 299.00" class="{{ $inputClass }}"></label>
            <label for="stock_quantity" class="text-[var(--text-ink)] flex flex-col gap-2 font-semibold">Stock<input name="stock_quantity" max="99999" id="stock_quantity" type="number" step="1" min="0" required value="{{ old('stock_quantity', $product->stock_quantity ?? 0) }}" class="{{ $inputClass }}"></label>
        </div>
        <div data-pf-single-weight class="space-y-2">
            <label for="weight" class="font-semibold">Weight per item</label>
            <div class="flex gap-2">
                <input id="weight" name="weight" type="number" required min="1" max="100000" step="1" value="{{ old('weight', $product->weight_grams) }}" data-pf-weight class="{{ $inputClass }}">
                <select name="weight_unit" aria-label="Weight unit" data-pf-weight-unit class="{{ $inputClass }}"><option value="g" @selected(old('weight_unit', 'g') === 'g')>g</option><option value="kg" @selected(old('weight_unit', 'g') === 'kg')>kg</option></select>
            </div>
            <p class="text-xs text-[var(--text-muted)]">1 g to 100 kg per item. This sets the shipping fee.</p>
        </div>
        <div data-pf-variant-inventory class="space-y-4">
            <p class="font-semibold">Same for every version? Fill this in once.</p><div class="flex flex-wrap items-end gap-3 rounded-sm bg-[var(--bg-soft)] p-3"><label class="text-[var(--text-ink)] flex flex-col gap-1 text-xs">Price (₱)<input type="number" min="0" step="0.01" data-pf-bulk-price class="{{ $inputClass }}" placeholder="0.00"></label><label class="text-[var(--text-ink)] flex flex-col gap-1 text-xs">Stock<input type="number" min="0" step="1" data-pf-bulk-stock class="{{ $inputClass }}" placeholder="0"></label><span class="text-[var(--text-ink)] flex flex-col gap-1 text-xs">Weight<span class="flex gap-1"><input type="number" min="0" step="any" data-pf-bulk-weight aria-label="Weight for all versions" class="{{ $inputClass }} w-24" placeholder="0"><select data-pf-bulk-weight-unit aria-label="Weight unit for all versions" class="{{ $inputClass }} w-20"><option value="g">g</option><option value="kg">kg</option></select></span></span><button type="button" data-pf-apply-bulk class="{{ $buttonClass }}">Apply to all</button></div>
            <p class="text-xs text-[var(--text-muted)] sm:hidden">Swipe sideways to see every column.</p><div class="max-h-[45vh] overflow-auto rounded-sm border border-[var(--border-input)]"><table class="w-full min-w-[650px] text-left text-sm"><thead class="sticky top-0 z-20 bg-[var(--bg-soft)] text-xs text-[var(--text-label)]"><tr><th class="sticky left-0 z-30 bg-[var(--bg-soft)] p-3 text-[var(--text-ink)]">Version</th><th class="p-3">Price (₱)</th><th class="p-3">Stock</th><th class="p-3">Weight</th><th data-pf-sku-col class="p-3">SKU (optional)</th><th class="p-3">On sale</th></tr></thead><tbody data-pf-variant-body></tbody></table></div>
        </div>
        <div class="flex items-baseline justify-between border-y border-[var(--border-hairline)] py-4"><span class="text-[var(--text-muted)]">Total stock</span><span class="text-[var(--text-ink)] font-serif text-2xl"><strong data-pf-total-stock class="text-[var(--text-ink)]">0</strong> <span class="text-[var(--text-ink)] font-sans text-sm">pieces</span></span></div>
    </section>
    <section data-pf-step="4" hidden class="space-y-5">
        <h3 class="font-serif text-xl text-[var(--text-ink)]">Parcel size and review</h3>
        <div class="space-y-2">
            <p class="font-semibold">Packed parcel size</p>
            <p class="text-xs text-[var(--text-muted)]">Measure the box or pouch you ship it in.</p>
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach(['length', 'width', 'height'] as $dimension)
                <label class="flex flex-col gap-2">{{ ucfirst($dimension) }} (cm)<input name="dimensions[{{ $dimension }}]" type="number" min="0.01" step="any" required value="{{ old('dimensions.'.$dimension, $product->dimensions[$dimension] ?? '') }}" class="{{ $inputClass }}"></label>
            @endforeach
        </div>
        </div>
        <details data-pf-optional class="rounded-sm border border-[var(--border-input)] p-4">
            <summary class="cursor-pointer font-semibold text-[var(--text-ink)]">More details <span class="font-normal text-[var(--text-muted)]">(brand, condition, low-stock alert)</span></summary>
            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <label class="flex flex-col gap-2">Brand<input name="brand" maxlength="100" value="{{ old('brand', $product->brand ?? 'No brand') }}" class="{{ $inputClass }}"></label>
                <label class="flex flex-col gap-2">Condition<input name="condition" maxlength="100" value="{{ old('condition', $product->condition ?? 'New') }}" class="{{ $inputClass }}"></label>
                <label for="low_stock_threshold" class="text-[var(--text-ink)] flex flex-col gap-2">Low-stock alert at<input name="low_stock_threshold" id="low_stock_threshold" type="number" step="1" min="0" value="{{ old('low_stock_threshold', $product->low_stock_threshold ?? 5) }}" class="{{ $inputClass }}"></label>
            </div>
        </details>
        <div class="space-y-2">
            <p class="font-semibold">Your listing</p>
        <div class="flex gap-5 rounded-sm border border-[var(--border-input)] bg-[var(--bg-soft)] p-5"><img data-pf-review-cover hidden alt="Selected product cover" class="size-24 shrink-0 rounded-sm object-cover"><div class="min-w-0 space-y-2"><h4 data-pf-review-name class="text-[var(--text-ink)] break-words font-serif text-xl"></h4><p data-pf-review-category class="text-xs text-[var(--text-muted)]"></p><p data-pf-review-options class="text-xs text-[var(--text-ink)]"></p><p data-pf-review-summary class="font-semibold text-[var(--text-ink)]"></p></div></div>
        </div>
        <label for="status" @unless($product->exists) hidden @endunless class="text-[var(--text-ink)] flex flex-col gap-2 font-semibold">Publication status<select id="status" name="status" class="{{ $inputClass }}"><option value="active" @selected(old('status', $product->exists ? $product->status : 'active') === 'active')>Publish — visible to buyers</option><option value="draft" @selected(old('status', $product->exists ? $product->status : 'active') === 'draft')>Save as draft — only visible to you</option><option value="inactive" @selected(old('status', $product->status) === 'inactive')>Inactive — hidden from buyers</option></select></label>
    </section>
</div>
