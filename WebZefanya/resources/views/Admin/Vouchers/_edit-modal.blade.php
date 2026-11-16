<dialog id="edit-voucher-dialog" class="voucher-edit-dialog" aria-labelledby="edit-voucher-title" aria-describedby="edit-voucher-description" data-return-url="{{ route('admin.vouchers.index') }}">
    <form id="edit-voucher-form" method="POST" action="{{ route('admin.vouchers.update', $voucher) }}">
        @csrf @method('PUT')
        <input type="hidden" name="_modal" value="admin-voucher-edit">
        <header class="voucher-edit-header">
            <div><p class="voucher-edit-eyebrow">Marketplace promotions / {{ $voucher->code }}</p><h2 id="edit-voucher-title" tabindex="-1">Edit voucher</h2><p id="edit-voucher-description">Update your discount, eligibility, and schedule.</p></div>
            <a href="{{ route('admin.vouchers.index') }}" aria-label="Close edit voucher" class="voucher-edit-close"><i data-lucide="x" aria-hidden="true"></i></a>
        </header>
        <div class="voucher-edit-body">
            @if($errors->any())
                <div class="voucher-edit-errors" role="alert" tabindex="-1"><strong>Check these details before saving</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul><p>Your entries have been kept.</p></div>
            @endif

@use('App\Enums\VoucherScope')
@use('App\Enums\VoucherType')
@use('App\Http\Requests\Seller\SellerVoucherRequest')
@use('App\Models\Seller\StoreSale')

@php
    $marketplace = true;
    $locked = $voucher?->rulesLocked() === true;
    $scope = old('scope', $voucher?->effectiveScope()->value ?? VoucherScope::Platform->value);
    $inputClass = 'voucher-edit-input';
    $labelClass = 'voucher-edit-label';
    $errorClass = 'voucher-edit-error';
    $hintClass = 'voucher-edit-hint';
    $pesos = fn ($centavos) => $centavos === null ? null : number_format($centavos / 100, 2, '.', '');
    $localTime = fn ($at) => $at?->copy()->timezone(StoreSale::TIMEZONE)->format('Y-m-d\TH:i');
    $scalar = fn ($value) => is_scalar($value) ? $value : '';

    $type = old('type', $voucher?->type->value ?? VoucherType::Percent->value);
    $percentValue = old('percent', $voucher?->type === VoucherType::Percent ? $voucher->value : null);
    $fixedValue = old('fixed_amount', $voucher?->type === VoucherType::Fixed ? $pesos($voucher->value) : null);
    $maxDiscount = old('max_discount', $pesos($voucher?->max_discount));
    $availability = old('availability', $voucher?->is_claimable ? 'claimable' : 'code_only');
    $appliesTo = old('applies_to', ($voucher?->applies_to_all_products ?? true) ? 'all' : 'selected');
    $selected = array_map('intval', array_filter((array) old('product_ids', $voucher?->products->modelKeys() ?? []), 'is_scalar'));
@endphp

<section class="voucher-edit-section">
<h3>01 <span>Voucher & discount</span></h3>
@if($locked)
    <div class="voucher-edit-locked">
        <p class="{{ $labelClass }}">Voucher</p>
        <p class="mt-1 text-sm text-[var(--text-ink)]"><span class="font-mono font-semibold">{{ $voucher->code }}</span> — {{ $voucher->offerSummary() }}</p>
        @if($marketplace)
            <p class="{{ $hintClass }}">{{ $voucher->effectiveScope()->label() }}. Marketplace funded.</p>
        @endif
        <p class="{{ $hintClass }}">Buyers have claimed or used this voucher. You can only extend its end date or raise its limits, or end it.</p>
    </div>
@else
    @if($marketplace)
        <fieldset>
            <legend class="{{ $labelClass }}">Kind</legend>
            <div class="voucher-edit-choices">
                <label class="voucher-edit-choice">
                    <input type="radio" name="scope" value="{{ VoucherScope::Platform->value }}" @checked($scope === VoucherScope::Platform->value) @if($errors->has('scope')) aria-invalid="true" aria-describedby="edit-error-scope" @endif>
                    Platform voucher, such as PLAT100. It discounts merchandise.
                </label>
                <label class="voucher-edit-choice">
                    <input type="radio" name="scope" value="{{ VoucherScope::Shipping->value }}" @checked($scope === VoucherScope::Shipping->value) @if($errors->has('scope')) aria-invalid="true" aria-describedby="edit-error-scope" @endif>
                    Shipping voucher, such as FREESHIP. It discounts shipping fees.
                </label>
            </div>
            <p class="{{ $hintClass }}">Marketplace funded.</p>
            @error('scope')<p id="edit-error-scope" class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </fieldset>
    @endif

    <div>
        <label class="{{ $labelClass }}" for="edit-voucher-code">Code</label>
        <input id="edit-voucher-code" name="code" required minlength="4" maxlength="12" pattern="[A-Za-z0-9]{4,12}" value="{{ $scalar(old('code', $voucher?->code)) }}" class="mt-1 uppercase {{ $inputClass }}" @if($errors->has('code')) aria-invalid="true" aria-describedby="edit-error-code" @endif>
        <p class="{{ $hintClass }}">4 to 12 letters and numbers. Saved in capitals, and must be unique across Zefanya.</p>
        @error('code')<p id="edit-error-code" class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>

    <fieldset>
        <legend class="{{ $labelClass }}">Discount</legend>
        <div class="voucher-edit-choices">
            @foreach(VoucherType::cases() as $case)
                <label class="voucher-edit-choice">
                    <input type="radio" name="type" value="{{ $case->value }}" @checked($type === $case->value) @if($errors->has('type')) aria-invalid="true" aria-describedby="edit-error-type" @endif>
                    {{ $case->label() }}
                </label>
            @endforeach
        </div>
        @error('type')<p id="edit-error-type" class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </fieldset>

    <div data-edit-voucher-type="{{ VoucherType::Percent->value }}" class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="{{ $labelClass }}" for="edit-voucher-percent">Percentage off (1–99)</label>
            <input id="edit-voucher-percent" name="percent" type="number" min="{{ SellerVoucherRequest::MIN_PERCENT }}" max="{{ SellerVoucherRequest::MAX_PERCENT }}" step="1" value="{{ $scalar($percentValue) }}" class="mt-1 {{ $inputClass }}" @if($errors->has('percent')) aria-invalid="true" aria-describedby="edit-error-percent" @endif>
            @error('percent')<p id="edit-error-percent" class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="{{ $labelClass }}" for="edit-voucher-cap">Maximum discount (PHP)</label>
            <input id="edit-voucher-cap" name="max_discount" type="number" min="0.01" step="0.01" value="{{ $scalar($maxDiscount) }}" class="mt-1 {{ $inputClass }}" @if($errors->has('max_discount')) aria-invalid="true" aria-describedby="edit-error-max_discount" @endif>
            @error('max_discount')<p id="edit-error-max_discount" class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
    </div>

    <div data-edit-voucher-type="{{ VoucherType::Fixed->value }}">
        <label class="{{ $labelClass }}" for="edit-voucher-fixed">Amount off (PHP)</label>
        <input id="edit-voucher-fixed" name="fixed_amount" type="number" min="0.01" step="0.01" value="{{ $scalar($fixedValue) }}" class="mt-1 {{ $inputClass }}" @if($errors->has('fixed_amount')) aria-invalid="true" aria-describedby="edit-error-fixed_amount" @endif>
        @error('fixed_amount')<p id="edit-error-fixed_amount" class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
@endif

</section>
<section class="voucher-edit-section">
<h3>02 <span>Limits & schedule</span></h3>
@unless($locked)
<div>
    <label class="{{ $labelClass }}" for="edit-voucher-min">Minimum spend (PHP, optional)</label>
    <input id="edit-voucher-min" name="min_spend" type="number" min="0" step="0.01" value="{{ $scalar(old('min_spend', $pesos($voucher?->min_spend))) }}" class="mt-1 {{ $inputClass }}" @if($errors->has('min_spend')) aria-invalid="true" aria-describedby="edit-error-min_spend" @endif>
    <p class="{{ $hintClass }}">Counted on eligible products only, at their sale price.</p>
    @error('min_spend')<p id="edit-error-min_spend" class="{{ $errorClass }}">{{ $message }}</p>@enderror
</div>
@endunless

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="{{ $labelClass }}" for="edit-voucher-usage">Total usage limit (optional)</label>
        <input id="edit-voucher-usage" name="usage_limit" type="number" min="1" step="1" value="{{ $scalar(old('usage_limit', $voucher?->usage_limit)) }}" class="mt-1 {{ $inputClass }}" @if($errors->has('usage_limit')) aria-invalid="true" aria-describedby="edit-error-usage_limit" @endif>
        @error('usage_limit')<p id="edit-error-usage_limit" class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
    <div>
        <label class="{{ $labelClass }}" for="edit-voucher-per-buyer">Limit per buyer (optional)</label>
        <input id="edit-voucher-per-buyer" name="per_buyer_limit" type="number" min="1" step="1" value="{{ $scalar(old('per_buyer_limit', $voucher?->per_buyer_limit)) }}" class="mt-1 {{ $inputClass }}" @if($errors->has('per_buyer_limit')) aria-invalid="true" aria-describedby="edit-error-per_buyer_limit" @endif>
        @error('per_buyer_limit')<p id="edit-error-per_buyer_limit" class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    @unless($locked)
    <div>
        <label class="{{ $labelClass }}" for="edit-voucher-starts">Starts (Philippine time)</label>
        <input id="edit-voucher-starts" name="starts_at" type="datetime-local" required value="{{ $scalar(old('starts_at', $localTime($voucher?->starts_at))) }}" class="mt-1 {{ $inputClass }}" @if($errors->has('starts_at')) aria-invalid="true" aria-describedby="edit-error-starts_at" @endif>
        @error('starts_at')<p id="edit-error-starts_at" class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
    @endunless
    <div>
        <label class="{{ $labelClass }}" for="edit-voucher-ends">Ends (Philippine time)</label>
        <input id="edit-voucher-ends" name="expires_at" type="datetime-local" required value="{{ $scalar(old('expires_at', $localTime($voucher?->expires_at))) }}" class="mt-1 {{ $inputClass }}" @if($errors->has('expires_at')) aria-invalid="true" aria-describedby="edit-error-expires_at" @endif>
        @error('expires_at')<p id="edit-error-expires_at" class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>
</div>

</section>
@unless($locked)
<section class="voucher-edit-section">
<h3>03 <span>Availability & products</span></h3>
<fieldset>
    <legend class="{{ $labelClass }}">How buyers get it</legend>
    <div class="voucher-edit-choices">
        <label class="voucher-edit-choice">
            <input type="radio" name="availability" value="code_only" @checked($availability === 'code_only') @if($errors->has('availability')) aria-invalid="true" aria-describedby="edit-error-availability" @endif>
            Code only: buyers need the code from you
        </label>
        <label class="voucher-edit-choice">
            <input type="radio" name="availability" value="claimable" @checked($availability === 'claimable') @if($errors->has('availability')) aria-invalid="true" aria-describedby="edit-error-availability" @endif>
            Claimable: available for buyer discovery when supported
        </label>
    </div>
    <p class="{{ $hintClass }}">Marketplace voucher discovery and claiming are not currently available. Buyers can still enter the code at checkout.</p>
    @error('availability')<p id="edit-error-availability" class="{{ $errorClass }}">{{ $message }}</p>@enderror
</fieldset>

<fieldset>
    <legend class="{{ $labelClass }}">Applies to</legend>
    <div class="voucher-edit-choices">
        <label class="voucher-edit-choice">
            <input type="radio" name="applies_to" value="all" @checked($appliesTo === 'all') @if($errors->has('applies_to')) aria-invalid="true" aria-describedby="edit-error-applies_to" @endif>
            {{ $marketplace ? 'All products' : 'All my products' }}
        </label>
        <label class="voucher-edit-choice">
            <input type="radio" name="applies_to" value="selected" @checked($appliesTo === 'selected') @if($errors->has('applies_to')) aria-invalid="true" aria-describedby="edit-error-applies_to" @endif>
            Selected products
        </label>
    </div>
    @error('applies_to')<p id="edit-error-applies_to" class="{{ $errorClass }}">{{ $message }}</p>@enderror

    <div id="edit-voucher-products" class="voucher-edit-products">
        @forelse($products as $product)
            <label class="voucher-edit-choice">
                <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" @checked(in_array($product->id, $selected, true))>
                {{ $product->name }}
            </label>
        @empty
            <p class="text-[var(--text-muted)]">{{ $marketplace ? 'There are no products yet.' : 'You have no products yet.' }}</p>
        @endforelse
    </div>
    @error('product_ids')<p id="edit-error-product_ids" class="{{ $errorClass }}">{{ $message }}</p>@enderror
    @error('product_ids.*')<p id="edit-error-product_ids-item" class="{{ $errorClass }}">{{ $message }}</p>@enderror
</fieldset>

<label class="flex items-center gap-2 text-sm font-semibold text-[var(--text-ink)]">
    <input type="hidden" name="is_active" value="0">
    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $voucher?->is_active ?? true)) @if($errors->has('is_active')) aria-invalid="true" aria-describedby="edit-error-is_active" @endif>
    Active
</label>
@endunless


@unless($locked)</section>@endunless
        </div>
        <footer class="voucher-edit-footer"><p><i data-lucide="clock-3" aria-hidden="true"></i> Philippine time (UTC+8)</p><div><a href="{{ route('admin.vouchers.index') }}" class="voucher-edit-cancel">Cancel</a><button type="submit" class="voucher-edit-save"><i data-lucide="check" aria-hidden="true"></i>Save changes</button></div></footer>
    </form>
</dialog>
