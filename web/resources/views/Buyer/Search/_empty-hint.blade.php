{{--
    The part of an empty results page that says what to try next.
    Expects: $term (string), $suggestion (?string), $suggestionUrl (?string),
             $priceFilters (array), $products (empty paginator or collection)
--}}
@php
    $pastEnd = method_exists($products, 'total') && $products->total() > 0;
@endphp
<div class="mb-6 text-[14px] text-buyer-ink-soft">
    @if ($pastEnd)
        <p>That page is past the end of these results.</p>
        <a href="{{ request()->fullUrlWithQuery(['page' => 1]) }}" class="mt-1 inline-block font-semibold text-primary-800 hover:text-primary-900">Go to the first page</a>
    @elseif ($suggestion)
        <p>We couldn't find anything matching "{{ $term }}".</p>
        <p class="mt-1">Did you mean <a href="{{ $suggestionUrl }}" class="font-semibold text-primary-800 underline hover:text-primary-900">{{ $suggestion }}</a>?</p>
    @elseif ($priceFilters !== [])
        <p>Nothing matches {{ $term !== '' ? '"'.$term.'" in' : '' }} this price range. Try a wider range or clear the price filter.</p>
    @elseif ($term !== '')
        <p>We couldn't find anything matching "{{ $term }}". Check the spelling or try a more general word.</p>
    @else
        <p>Try adjusting your filters.</p>
    @endif
</div>
