{{--
    Price range form for a search or catalog results page.
    Expects: $action (url), $priceError (?string), $priceFilters (array)
    The other filters ride along as hidden inputs, and a new filter starts at page 1.
--}}
@php
    $priceValue = fn (string $key) => is_scalar(request($key)) ? request($key) : '';
    $hasPrice = $priceFilters !== [] || $priceError !== null;
@endphp
<form method="GET" action="{{ $action }}" class="mb-6 rounded-xl border border-buyer-line bg-white p-4" aria-label="Filter by price">
    @foreach (['q', 'category', 'seller', 'sort'] as $carried)
        @if (is_scalar(request($carried)) && request($carried) !== '')
            <input type="hidden" name="{{ $carried }}" value="{{ request($carried) }}">
        @endif
    @endforeach
    <div class="flex flex-wrap items-end gap-3">
        <label class="text-[12px] font-semibold text-buyer-ink">Min price (PHP)
            <input type="number" name="min_price" min="0" step="0.01" value="{{ $priceValue('min_price') }}"
                   class="mt-1 block h-9 w-32 rounded-lg border border-buyer-line px-3 text-[13px] font-normal text-buyer-ink">
        </label>
        <label class="text-[12px] font-semibold text-buyer-ink">Max price (PHP)
            <input type="number" name="max_price" min="0" step="0.01" value="{{ $priceValue('max_price') }}"
                   class="mt-1 block h-9 w-32 rounded-lg border border-buyer-line px-3 text-[13px] font-normal text-buyer-ink">
        </label>
        <button type="submit" class="h-9 rounded-lg bg-primary-800 px-4 text-[13px] font-semibold text-white transition-colors hover:bg-primary-700">Apply</button>
        @if ($hasPrice)
            <a href="{{ request()->fullUrlWithQuery(['min_price' => null, 'max_price' => null, 'page' => null]) }}" class="self-center text-[13px] font-medium text-primary-800 hover:text-primary-900">Clear price</a>
        @endif
    </div>
    @if ($priceError)
        <p role="alert" class="mt-2 text-[12.5px] font-medium text-red-700">{{ $priceError }} Showing results without a price filter.</p>
    @endif
</form>
