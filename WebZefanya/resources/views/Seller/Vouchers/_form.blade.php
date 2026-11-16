{{-- Fields shared by create and edit. Once a buyer claims or uses a voucher, only its end date and limits stay editable. --}}
@use('App\Enums\VoucherScope')
@use('App\Enums\VoucherType')
@use('App\Http\Requests\Seller\SellerVoucherRequest')
@use('App\Models\Seller\StoreSale')

@php
    $marketplace = $marketplace ?? false;
    $locked = $voucher?->rulesLocked() === true;
    $scope = old('scope', $voucher?->effectiveScope()->value ?? VoucherScope::Platform->value);
    $inputClass = 'w-full rounded-md border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-ink)]';
    $labelClass = 'block text-sm font-semibold text-[var(--text-ink)]';
    $errorClass = 'mt-1 text-sm text-error';
    $hintClass = 'mt-1 text-[12px] text-[var(--text-muted)]';
    $pesos = fn ($centavos) => $centavos === null ? null : number_format($centavos / 100, 2, '.', '');
    $localTime = fn ($at) => $at?->copy()->timezone(StoreSale::TIMEZONE)->format('Y-m-d\TH:i');
    $scalar = fn ($value) => is_scalar($value) ? $value : '';

    $type = old('type', $voucher?->type->value ?? VoucherType::Percent->value);
    $percentValue = old('percent', $voucher?->type === VoucherType::Percent ? $voucher->value : null);
    $fixedValue = old('fixed_amount', $voucher?->type === VoucherType::Fixed ? $pesos($voucher->value) : null);
    $maxDiscount = old('max_discount', $pesos($voucher?->max_discount));
    $availability = old('availability', $voucher?->is_claimable ? 'claimable' : 'code_only');
    $appliesTo = old('applies_to', ($voucher?->applies_to_all_products ?? true) ? 'all' : 'selected');
    $selected = array_map('intval', (array) old('product_ids', $voucher?->products->modelKeys() ?? []));
@endphp

@if($locked)
    <div>
        <p class="{{ $labelClass }}">Voucher</p>
        <p class="mt-1 text-sm text-[var(--text-ink)]"><span class="font-mono font-semibold">{{ $voucher->code }}</span> — {{ $voucher->offerSummary() }}</p>
        @if($marketplace)
            <p class="{{ $hintClass }}">{{ $voucher->effectiveScope()->label() }}. The marketplace funds this discount.</p>
        @endif
        <p class="{{ $hintClass }}">Buyers have claimed or used this voucher. You can only extend its end date or raise its limits, or end it.</p>
    </div>
@else
    @if($marketplace)
        <fieldset>
            <legend class="{{ $labelClass }}">Kind</legend>
            <div class="mt-2 space-y-1 text-sm text-[var(--text-ink)]">
                <label class="flex items-center gap-2">
                    <input type="radio" name="scope" value="{{ VoucherScope::Platform->value }}" @checked($scope === VoucherScope::Platform->value)>
                    Platform voucher, such as PLAT100. It discounts merchandise.
                </label>
                <label class="flex items-center gap-2">
                    <input type="radio" name="scope" value="{{ VoucherScope::Shipping->value }}" @checked($scope === VoucherScope::Shipping->value)>
                    Shipping voucher, such as FREESHIP. It discounts shipping fees.
                </label>
            </div>
            <p class="{{ $hintClass }}">The marketplace funds this discount.</p>
            @error('scope')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </fieldset>
    @endif

    <div>
        <label class="{{ $labelClass }}" for="voucher-code">Code</label>
        <input id="voucher-code" name="code" required minlength="4" maxlength="12" pattern="[A-Za-z0-9]{4,12}" value="{{ $scalar(old('code', $voucher?->code)) }}" class="mt-1 uppercase {{ $inputClass }}">
        <p class="{{ $hintClass }}">4 to 12 letters and numbers. Saved in capitals, and must be unique across Zefanya.</p>
        @error('code')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>

    <fieldset>
        <legend class="{{ $labelClass }}">Discount</legend>
        <div class="mt-2 flex gap-5 text-sm text-[var(--text-ink)]">
            @foreach(VoucherType::cases() as $case)
                <label class="flex items-center gap-2">
                    <input type="radio" name="type" value="{{ $case->value }}" @checked($type === $case->value)>
                    {{ $case->label() }}
                </label>
            @endforeach
        </div>
        @error('type')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </fieldset>

    <div data-voucher-type="{{ VoucherType::Percent->value }}" class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="{{ $labelClass }}" for="voucher-percent">Percentage off (1–99)</label>
            <input id="voucher-percent" name="percent" type="number" min="{{ SellerVoucherRequest::MIN_PERCENT }}" max="{{ SellerVoucherRequest::MAX_PERCENT }}" step="1" value="{{ $scalar($percentValue) }}" class="mt-1 {{ $inputClass }}">
            @error('percent')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="{{ $labelClass }}" for="voucher-cap">Maximum discount (PHP)</label>
            <input id="voucher-cap" name="max_discount" type="number" min="0.01" step="0.01" value="{{ $scalar($maxDiscount) }}" class="mt-1 {{ $inputClass }}">
            @error('max_discount')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
    </div>

    <div data-voucher-type="{{ VoucherType::Fixed->value }}">
        <label class="{{ $labelClass }}" for="voucher-fixed">Amount off (PHP)</label>
        <input id="voucher-fixed" name="fixed_amount" type="number" min="0.01" step="0.01" value="{{ $scalar($fixedValue) }}" class="mt-1 {{ $inputClass }}">
        @error('fixed_amount')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
@endif

@unless($locked)
<div>
    <label class="{{ $labelClass }}" for="voucher-min">Minimum spend (PHP, optional)</label>
    <input id="voucher-min" name="min_spend" type="number" min="0" step="0.01" value="{{ $scalar(old('min_spend', $pesos($voucher?->min_spend))) }}" class="mt-1 {{ $inputClass }}">
    <p class="{{ $hintClass }}">Counted on eligible products only, at their sale price.</p>
    @error('min_spend')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
</div>
@endunless

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="{{ $labelClass }}" for="voucher-usage">Total usage limit (optional)</label>
        <input id="voucher-usage" name="usage_limit" type="number" min="1" step="1" value="{{ $scalar(old('usage_limit', $voucher?->usage_limit)) }}" class="mt-1 {{ $inputClass }}">
        @error('usage_limit')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="{{ $labelClass }}" for="voucher-per-buyer">Limit per buyer (optional)</label>
        <input id="voucher-per-buyer" name="per_buyer_limit" type="number" min="1" step="1" value="{{ $scalar(old('per_buyer_limit', $voucher?->per_buyer_limit)) }}" class="mt-1 {{ $inputClass }}">
        @error('per_buyer_limit')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    @unless($locked)
    <div>
        <label class="{{ $labelClass }}" for="voucher-starts">Starts (Philippine time)</label>
        <input id="voucher-starts" name="starts_at" type="datetime-local" required value="{{ $scalar(old('starts_at', $localTime($voucher?->starts_at))) }}" class="mt-1 {{ $inputClass }}">
        @error('starts_at')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
    @endunless
    <div>
        <label class="{{ $labelClass }}" for="voucher-ends">Ends (Philippine time)</label>
        <input id="voucher-ends" name="expires_at" type="datetime-local" required value="{{ $scalar(old('expires_at', $localTime($voucher?->expires_at))) }}" class="mt-1 {{ $inputClass }}">
        @error('expires_at')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
</div>

@unless($locked)
<fieldset>
    <legend class="{{ $labelClass }}">How buyers get it</legend>
    <div class="mt-2 space-y-1 text-sm text-[var(--text-ink)]">
        <label class="flex items-center gap-2">
            <input type="radio" name="availability" value="code_only" @checked($availability === 'code_only')>
            Code only: buyers need the code from you
        </label>
        <label class="flex items-center gap-2">
            <input type="radio" name="availability" value="claimable" @checked($availability === 'claimable')>
            Claimable: shown to buyers on your store, product pages and in their cart
        </label>
    </div>
    @error('availability')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
</fieldset>

<fieldset>
    <legend class="{{ $labelClass }}">Applies to</legend>
    <div class="mt-2 flex gap-5 text-sm text-[var(--text-ink)]">
        <label class="flex items-center gap-2">
            <input type="radio" name="applies_to" value="all" @checked($appliesTo === 'all')>
            {{ $marketplace ? 'All products' : 'All my products' }}
        </label>
        <label class="flex items-center gap-2">
            <input type="radio" name="applies_to" value="selected" @checked($appliesTo === 'selected')>
            Selected products
        </label>
    </div>
    @error('applies_to')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror

    <div id="voucher-products" class="mt-3 max-h-56 space-y-1 overflow-y-auto rounded-md border border-[var(--border-input)] p-3 text-sm text-[var(--text-ink)]">
        @forelse($products as $product)
            <label class="flex items-center gap-2">
                <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" @checked(in_array($product->id, $selected, true))>
                {{ $product->name }}
            </label>
        @empty
            <p class="text-[var(--text-muted)]">{{ $marketplace ? 'There are no products yet.' : 'You have no products yet.' }}</p>
        @endforelse
    </div>
    @error('product_ids')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    @error('product_ids.*')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
</fieldset>

<label class="flex items-center gap-2 text-sm font-semibold text-[var(--text-ink)]">
    <input type="hidden" name="is_active" value="0">
    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $voucher?->is_active ?? true))>
    Active
</label>
@endunless

@push('scripts')
<script>
    (function () {
        // Show only the discount inputs for the chosen type.
        const syncType = () => {
            const chosen = document.querySelector('input[name="type"]:checked')?.value;
            document.querySelectorAll('[data-voucher-type]').forEach((block) => {
                const active = block.dataset.voucherType === chosen;
                block.classList.toggle('hidden', !active);
                block.querySelectorAll('input').forEach((input) => {
                    input.disabled = !active;
                    input.required = active;
                });
            });
        };
        document.querySelectorAll('input[name="type"]').forEach((input) => input.addEventListener('change', syncType));
        syncType();

        // The product list only matters for a selected-products voucher.
        const products = document.getElementById('voucher-products');
        if (!products) return;

        const syncProducts = () => {
            const selected = document.querySelector('input[name="applies_to"]:checked')?.value === 'selected';
            products.classList.toggle('hidden', !selected);
        };
        document.querySelectorAll('input[name="applies_to"]').forEach((input) => input.addEventListener('change', syncProducts));
        syncProducts();
    })();
</script>
@endpush
