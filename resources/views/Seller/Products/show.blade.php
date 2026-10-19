@csrf
@if($product->exists) @method('PUT') @endif

@if ($errors->any())
    <div class="px-3 py-3 bg-[#fbe9e7] border border-[#e8a49c] rounded-sm font-sans text-[12.5px] text-[#a5333d]">{{ $errors->first() }}</div>
@endif

<div class="flex gap-4">
    <div class="flex flex-col flex-1">
        <label for="name" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[#4f4446] mb-2">Product name *</label>
        <input type="text" name="name" id="name" required value="{{ old('name', $product->name) }}" class="px-3 py-2.5 border border-[#e8e2e0] rounded-sm font-sans text-[13.5px] text-[#1e1b1b] focus:outline-none focus:border-[#79545c]">
        @error('name') <span class="font-sans text-[11.5px] text-[#a5333d] mt-1.5">{{ $message }}</span> @enderror
    </div>
    <div class="flex flex-col flex-[0_0_180px]">
        <label class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[#4f4446] mb-2">Category</label>
        <div class="flex items-center gap-2 px-3 py-2.5 bg-[#faf2f1] border border-[#e8e2e0] rounded-sm text-[#4f4446] font-sans text-[13.5px]">
            <i data-lucide="lock" width="14" height="14" class="text-primary-800 flex-shrink-0"></i>
            <span>{{ $lockedCategory->name ?? 'Uncategorized' }}</span>
        </div>
        <span class="font-sans text-[10.5px] italic text-[#4f4446] mt-1.5">Set by your seller registration — not editable per product.</span>
    </div>
</div>

<div class="flex flex-col flex-1">
    <label for="description" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[#4f4446] mb-2">Description</label>
    <textarea name="description" id="description" rows="4" class="px-3 py-2.5 border border-[#e8e2e0] rounded-sm font-sans text-[13.5px] text-[#1e1b1b] focus:outline-none focus:border-[#79545c]">{{ old('description', $product->description) }}</textarea>
</div>

<div class="flex gap-4">
    <div class="flex flex-col flex-1">
        <label for="price" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[#4f4446] mb-2">Price (₱) *</label>
        <input type="number" name="price" id="price" step="0.01" min="0" required value="{{ old('price', $product->price) }}" class="px-3 py-2.5 border border-[#e8e2e0] rounded-sm font-sans text-[13.5px] text-[#1e1b1b] focus:outline-none focus:border-[#79545c]">
        @error('price') <span class="font-sans text-[11.5px] text-[#a5333d] mt-1.5">{{ $message }}</span> @enderror
    </div>
    <div class="flex flex-col flex-1">
        <label for="stock_quantity" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[#4f4446] mb-2">Stock quantity *</label>
        <input type="number" name="stock_quantity" id="stock_quantity" min="0" required value="{{ old('stock_quantity', $product->stock_quantity) }}" class="px-3 py-2.5 border border-[#e8e2e0] rounded-sm font-sans text-[13.5px] text-[#1e1b1b] focus:outline-none focus:border-[#79545c]">
    </div>
    <div class="flex flex-col flex-1">
        <label for="low_stock_threshold" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[#4f4446] mb-2">Low-stock alert at</label>
        <input type="number" name="low_stock_threshold" id="low_stock_threshold" min="0" value="{{ old('low_stock_threshold', $product->low_stock_threshold) }}" class="px-3 py-2.5 border border-[#e8e2e0] rounded-sm font-sans text-[13.5px] text-[#1e1b1b] focus:outline-none focus:border-[#79545c]">
    </div>
</div>

<div class="flex flex-col flex-1">
    <label for="status" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[#4f4446] mb-2">Status *</label>
    <select name="status" id="status" required class="px-3 py-2.5 border border-[#e8e2e0] rounded-sm font-sans text-[13.5px] text-[#1e1b1b] focus:outline-none focus:border-[#79545c]">
        <option value="draft" {{ old('status', $product->status) === 'draft' ? 'selected' : '' }}>Draft (not visible to buyers)</option>
        <option value="active" {{ old('status', $product->status) === 'active' ? 'selected' : '' }}>Active</option>
        <option value="inactive" {{ old('status', $product->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
    </select>
</div>

<div class="flex flex-col flex-1">
    <label for="images" class="font-sans font-semibold text-[11px] tracking-[0.6px] uppercase text-[#4f4446] mb-2">Product photos {{ $product->exists ? '(add more)' : '*' }}</label>
    <div class="flex items-center gap-3">
        <label for="images" class="inline-flex items-center gap-2 h-10 px-4 bg-white border border-[#79545c] rounded-sm text-[#79545c] font-sans font-semibold text-[11.5px] cursor-pointer whitespace-nowrap hover:bg-[#79545c] hover:text-white transition-colors duration-200">
            <i data-lucide="upload" width="16" height="16"></i>
            <span>Choose photos</span>
        </label>
        <input type="file" name="images[]" id="images" accept="image/*" multiple class="absolute w-px h-px overflow-hidden">
        <span class="font-sans text-[12.5px] text-[#4f4446]" id="spFileName">No files selected</span>
    </div>
    @error('images') <span class="font-sans text-[11.5px] text-[#a5333d] mt-1.5">{{ $message }}</span> @enderror
    @error('images.*') <span class="font-sans text-[11.5px] text-[#a5333d] mt-1.5">{{ $message }}</span> @enderror
</div>

@if ($product->exists && $product->images->isNotEmpty())
    <div class="flex flex-wrap gap-3">
        @foreach ($product->images as $image)
            <div class="relative w-20 h-20 rounded-md overflow-hidden border border-[#e8e2e0]">
                <img src="{{ Storage::url($image->path) }}" alt="" class="w-full h-full object-cover">
                @if ($image->is_primary)
                    <span class="absolute bottom-0 left-0 right-0 px-1 py-0.5 bg-[rgba(30,27,27,0.7)] text-white text-[9px] text-center font-sans">Primary</span>
                @endif
                <form method="POST" action="{{ route('seller.products.images.destroy', [$product, $image]) }}"
                      onsubmit="return confirm('Remove this photo?');">
                    @csrf @method('DELETE')
                    <button type="submit" aria-label="Remove photo" class="flex items-center justify-center w-5 h-5 bg-[rgba(30,27,27,0.7)] text-white rounded-full cursor-pointer transition-colors duration-200 hover:bg-[rgba(30,27,27,0.85)]">
                        <i data-lucide="x" width="14" height="14"></i>
                    </button>
                </form>
            </div>
        @endforeach
    </div>
@endif

    <button type="submit" class="inline-flex items-center justify-center gap-2 h-[48px] w-full bg-neutral-950 text-white font-sans font-semibold text-[12.5px] tracking-[0.8px] rounded-sm border-none cursor-pointer transition-colors duration-200 hover:bg-[#79545c]">
    {{ $product->exists ? 'SAVE CHANGES' : 'CREATE PRODUCT' }}
</button>

<script>
document.getElementById('images')?.addEventListener('change', function () {
    var label = document.getElementById('spFileName');
    label.textContent = this.files.length ? this.files.length + ' file(s) selected' : 'No files selected';
});
</script>
