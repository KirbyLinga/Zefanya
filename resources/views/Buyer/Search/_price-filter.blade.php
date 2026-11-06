{{--
    Price range form for a search or catalog results page.
    Expects: $action (url), $priceError (?string), $priceFilters (array)
    The other filters ride along as hidden inputs, and a new filter starts at page 1.
--}}
@php
    $priceValue = fn (string $key) => is_scalar(request($key)) ? request($key) : '';
    $hasPrice = $priceFilters !== [] || $priceError !== null;
@endphp
<form method="GET" action="{{ $action }}" class="mb-6 rounded-xl border border-buyer-line bg-white p-3" data-price-filter-form aria-label="Filter by price">
    @foreach (['q', 'category', 'seller', 'sort', 'on_sale'] as $carried)
        @if (is_scalar(request($carried)) && request($carried) !== '')
            <input type="hidden" name="{{ $carried }}" value="{{ request($carried) }}">
        @endif
    @endforeach
    <div class="flex flex-wrap items-center gap-2 sm:gap-3">
        <label class="inline-flex items-center gap-2 text-[11px] sm:text-[12px] font-semibold text-buyer-ink">
            <span class="shrink-0">Min</span>
            <input type="number" name="min_price" min="0" step="0.01" placeholder="0" value="{{ $priceValue('min_price') }}"
                   class="h-8 w-24 sm:w-28 rounded-lg border border-buyer-line px-2 text-[12px] font-normal text-buyer-ink">
        </label>
        <span class="text-buyer-ink-soft">–</span>
        <label class="inline-flex items-center gap-2 text-[11px] sm:text-[12px] font-semibold text-buyer-ink">
            <span class="shrink-0">Max</span>
            <input type="number" name="max_price" min="0" step="0.01" placeholder="999999" value="{{ $priceValue('max_price') }}"
                   class="h-8 w-24 sm:w-28 rounded-lg border border-buyer-line px-2 text-[12px] font-normal text-buyer-ink">
        </label>
        <button type="submit" class="h-8 rounded-lg bg-primary-800 px-3 text-[11px] sm:text-[12px] font-semibold text-white transition-colors hover:bg-primary-700 shrink-0">Apply</button>
        @if ($hasPrice)
            <a href="{{ request()->fullUrlWithQuery(['min_price' => null, 'max_price' => null, 'page' => null]) }}" class="text-[11px] sm:text-[12px] font-medium text-primary-800 hover:text-primary-900">Clear</a>
        @endif
    </div>
    @if ($priceError)
        <p role="alert" class="mt-2 text-[11px] sm:text-[12.5px] font-medium text-red-700">{{ $priceError }} Showing results without a price filter.</p>
    @endif
</form>
