@php
    $saved = $wishlistIds->has($product->id);
    $label = $saved ? 'Remove from wishlist' : 'Add to wishlist';
@endphp
<button type="button" data-wishlist-toggle data-product-id="{{ $product->id }}"
        data-wishlist-url="{{ route('buyer.wishlist.toggle', $product->id, absolute: false) }}"
        aria-pressed="{{ $saved ? 'true' : 'false' }}" aria-label="{{ $label }}" title="{{ $label }}"
        style="{{ $saved ? 'color: #e11d48' : '' }}"
        class="{{ $heartPosition ?? 'absolute right-3 top-3 z-[2]' }} inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/90 text-neutral-400 shadow-md transition-colors hover:bg-white">
    <i data-lucide="heart" width="20" height="20" style="fill: {{ $saved ? 'currentColor' : 'none' }}; stroke: currentColor"></i>
</button>
