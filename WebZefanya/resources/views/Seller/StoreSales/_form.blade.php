{{-- Fields shared by create and edit. A running sale keeps its product and start time. --}}
@use('App\Enums\StoreSaleStatus')
@use('App\Enums\StoreSaleType')
@use('App\Models\Seller\StoreSale')

@php
    $running = $sale?->status() === StoreSaleStatus::Ongoing;
    $inputClass = 'w-full rounded-md border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-ink)]';
    $labelClass = 'block text-sm font-semibold text-[var(--text-ink)]';
    $errorClass = 'mt-1 text-sm text-error';
    $localTime = fn ($at) => $at?->copy()->timezone(StoreSale::TIMEZONE)->format('Y-m-d\TH:i');
    $type = old('type', $sale?->type->value ?? StoreSaleType::Percent->value);
    $percent = old('percent', $sale?->type === StoreSaleType::Percent ? $sale->value : null);
    $fixedPrice = old('fixed_price', $sale?->type === StoreSaleType::FixedPrice ? number_format($sale->value / 100, 2, '.', '') : null);
    $selectedProduct = (int) old('product_id', $sale?->product_id);
    $selectedVariant = (int) old('variant_id', $sale?->variant_id);
@endphp

@error('sale')
    <div role="alert" class="rounded-md border border-error p-4 text-sm text-error">{{ $message }}</div>
@enderror

@if($running)
    <div>
        <p class="{{ $labelClass }}">Product</p>
        <p class="mt-1 text-sm text-[var(--text-ink)]">{{ $sale->product?->name ?? 'Product' }} — {{ $sale->variant ? $sale->variant->label() : 'Whole product' }}</p>
        <p class="mt-1 text-[12px] text-[var(--text-muted)]">This sale is running, so its product and start time are locked.</p>
    </div>
@else
    <div>
        <label class="{{ $labelClass }}" for="sale-product">Product</label>
        <select id="sale-product" name="product_id" required class="mt-1 {{ $inputClass }}">
            <option value="">Choose a product</option>
            @foreach($products as $product)
                <option value="{{ $product->id }}" @selected($selectedProduct === $product->id)>{{ $product->name }}</option>
            @endforeach
        </select>
        @error('product_id')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="{{ $labelClass }}" for="sale-variant">Applies to</label>
        <select id="sale-variant" name="variant_id" class="mt-1 {{ $inputClass }}">
            <option value="">Whole product</option>
            @foreach($products as $product)
                @foreach($product->variants as $variant)
                    <option value="{{ $variant->id }}" data-product="{{ $product->id }}" @selected($selectedVariant === $variant->id)>{{ $variant->label() }} ({{ $variant->formattedPrice() }})</option>
                @endforeach
            @endforeach
        </select>
        @error('variant_id')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
@endif

<fieldset>
    <legend class="{{ $labelClass }}">Discount</legend>
    <div class="mt-2 flex gap-5 text-sm text-[var(--text-ink)]">
        @foreach(StoreSaleType::cases() as $case)
            <label class="flex items-center gap-2">
                <input type="radio" name="type" value="{{ $case->value }}" @checked($type === $case->value)>
                {{ $case->label() }}
            </label>
        @endforeach
    </div>
    @error('type')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
</fieldset>

<div data-sale-type="{{ StoreSaleType::Percent->value }}">
    <label class="{{ $labelClass }}" for="sale-percent">Percentage off (1–99)</label>
    <input id="sale-percent" name="percent" type="number" min="{{ StoreSale::MIN_PERCENT }}" max="{{ StoreSale::MAX_PERCENT }}" step="1" value="{{ is_scalar($percent) ? $percent : '' }}" class="mt-1 {{ $inputClass }}">
    @error('percent')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
</div>

<div data-sale-type="{{ StoreSaleType::FixedPrice->value }}">
    <label class="{{ $labelClass }}" for="sale-fixed-price">Sale price (PHP)</label>
    <input id="sale-fixed-price" name="fixed_price" type="number" min="0.01" step="0.01" value="{{ is_scalar($fixedPrice) ? $fixedPrice : '' }}" class="mt-1 {{ $inputClass }}">
    @error('fixed_price')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
</div>

<div>
    <label class="{{ $labelClass }}" for="sale-flash-quantity">Flash quantity</label>
    <input id="sale-flash-quantity" name="flash_quantity" type="number" min="1" max="1000000" step="1" value="{{ old('flash_quantity', $sale?->flash_quantity) }}" placeholder="Unlimited" class="mt-1 {{ $inputClass }}">
    <p class="mt-1 text-[12px] text-[var(--text-muted)]">Leave blank for an unlimited sale. Units past this quantity are charged at the regular price.</p>
    @error('flash_quantity')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="{{ $labelClass }}" for="sale-starts">Starts (Philippine time)</label>
        @if($running)
            <p id="sale-starts" class="mt-2 text-sm text-[var(--text-ink)]">{{ $sale->starts_at->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}</p>
        @else
            <input id="sale-starts" name="starts_at" type="datetime-local" required value="{{ old('starts_at', $localTime($sale?->starts_at)) }}" class="mt-1 {{ $inputClass }}">
            @error('starts_at')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        @endif
    </div>
    <div>
        <label class="{{ $labelClass }}" for="sale-ends">Ends (Philippine time)</label>
        <input id="sale-ends" name="ends_at" type="datetime-local" required value="{{ old('ends_at', $localTime($sale?->ends_at)) }}" class="mt-1 {{ $inputClass }}">
        @error('ends_at')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
</div>

@push('scripts')
<script>
    (function () {
        // Show only the discount input for the chosen type.
        const typeInputs = document.querySelectorAll('input[name="type"]');
        const syncType = () => {
            const chosen = document.querySelector('input[name="type"]:checked')?.value;
            document.querySelectorAll('[data-sale-type]').forEach((block) => {
                const active = block.dataset.saleType === chosen;
                block.classList.toggle('hidden', !active);
                const input = block.querySelector('input');
                input.disabled = !active;
                input.required = active;
            });
        };
        typeInputs.forEach((input) => input.addEventListener('change', syncType));
        syncType();

        // Offer only the selected product's variants.
        const product = document.getElementById('sale-product');
        const variant = document.getElementById('sale-variant');
        if (!product || !variant) return;

        const syncVariants = () => {
            [...variant.options].forEach((option) => {
                if (!option.dataset.product) return;
                const matches = option.dataset.product === product.value;
                option.hidden = !matches;
                option.disabled = !matches;
                if (!matches && option.selected) variant.value = '';
            });
        };
        product.addEventListener('change', syncVariants);
        syncVariants();
    })();
</script>
@endpush
