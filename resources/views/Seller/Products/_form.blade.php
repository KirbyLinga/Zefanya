@csrf
@if($product->exists) @method('PUT') @endif

@if ($errors->any())
    <div class="sp-error-summary">{{ $errors->first() }}</div>
@endif

<div class="sp-form-row">
    <div class="sp-form-field">
        <label for="name">Product name *</label>
        <input type="text" name="name" id="name" required value="{{ old('name', $product->name) }}">
        @error('name') <span class="sp-field-error">{{ $message }}</span> @enderror
    </div>
    <div class="sp-form-field sp-form-field--small">
        <label>Category</label>
        <div class="sp-locked-category">
            <i data-lucide="lock" width="14" height="14"></i>
            <span>{{ $lockedCategory->name ?? 'Uncategorized' }}</span>
        </div>
        <span class="sp-field-hint">Set by your seller registration — not editable per product.</span>
    </div>
</div>

<div class="sp-form-field">
    <label for="description">Description</label>
    <textarea name="description" id="description" rows="4">{{ old('description', $product->description) }}</textarea>
</div>

<div class="sp-form-row">
    <div class="sp-form-field">
        <label for="price">Price (₱) *</label>
        <input type="number" name="price" id="price" step="0.01" min="0" required value="{{ old('price', $product->price) }}">
        @error('price') <span class="sp-field-error">{{ $message }}</span> @enderror
    </div>
    <div class="sp-form-field">
        <label for="stock_quantity">Stock quantity *</label>
        <input type="number" name="stock_quantity" id="stock_quantity" min="0" required value="{{ old('stock_quantity', $product->stock_quantity) }}">
    </div>
    <div class="sp-form-field">
        <label for="low_stock_threshold">Low-stock alert at</label>
        <input type="number" name="low_stock_threshold" id="low_stock_threshold" min="0" value="{{ old('low_stock_threshold', $product->low_stock_threshold) }}">
    </div>
</div>

<div class="sp-form-field">
    <label for="status">Status *</label>
    <select name="status" id="status" required>
        <option value="draft" {{ old('status', $product->status) === 'draft' ? 'selected' : '' }}>Draft (not visible to buyers)</option>
        <option value="active" {{ old('status', $product->status) === 'active' ? 'selected' : '' }}>Active</option>
        <option value="inactive" {{ old('status', $product->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
    </select>
</div>

<div class="sp-form-field">
    <label for="images">Product photos {{ $product->exists ? '(add more)' : '*' }}</label>
    <div class="sp-file-wrap">
        <label for="images" class="sp-file-btn">
            <i data-lucide="upload" width="16" height="16"></i>
            <span>Choose photos</span>
        </label>
        <input type="file" name="images[]" id="images" accept="image/*" multiple class="sp-file-input">
        <span class="sp-file-name" id="spFileName">No files selected</span>
    </div>
    @error('images') <span class="sp-field-error">{{ $message }}</span> @enderror
    @error('images.*') <span class="sp-field-error">{{ $message }}</span> @enderror
</div>

@if ($product->exists && $product->images->isNotEmpty())
    <div class="sp-existing-images">
        @foreach ($product->images as $image)
            <div class="sp-existing-image">
                <img src="{{ Storage::url($image->path) }}" alt="">
                @if ($image->is_primary)
                    <span class="sp-primary-tag">Primary</span>
                @endif
                <form method="POST" action="{{ route('seller.products.images.destroy', [$product, $image]) }}"
                      onsubmit="return confirm('Remove this photo?');">
                    @csrf @method('DELETE')
                    <button type="submit" aria-label="Remove photo"><i data-lucide="x" width="14" height="14"></i></button>
                </form>
            </div>
        @endforeach
    </div>
@endif

<button type="submit" class="sp-btn sp-btn--primary sp-btn--submit">
    {{ $product->exists ? 'SAVE CHANGES' : 'CREATE PRODUCT' }}
</button>

<script>
document.getElementById('images')?.addEventListener('change', function () {
    var label = document.getElementById('spFileName');
    label.textContent = this.files.length ? this.files.length + ' file(s) selected' : 'No files selected';
});
</script>
