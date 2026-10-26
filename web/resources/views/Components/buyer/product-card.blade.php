{{-- One product card for landing, home, shop/list, category, search, wishlist and related products. --}}
@php
    $product = $product ?? null;
    $url = $product ? ($url ?? route('buyer.products.show', $product->id)) : null;
    $name = $product?->name ?? 'Product coming soon';
    $imageUrl = $product?->catalogImageUrl(requireExistingFile: false);
    $seller = $product?->seller;
    $chooseVariant = $product && ($product->variants_count > 1 || $product->has_option_variants);
@endphp
<article class="buyer-product-card group flex h-full min-w-0 w-full flex-col bg-white group-data-[layout=list]/products:sm:flex-row">
    <div class="relative aspect-[4/5] w-full shrink-0 overflow-hidden bg-blush group-data-[layout=list]/products:sm:w-44">
        @if ($url)
            <a href="{{ $url }}" class="block h-full w-full" aria-label="View {{ $name }}">
                @if ($imageUrl)
                    <img src="{{ $imageUrl }}" alt="{{ $name }}" loading="lazy" class="h-full w-full object-cover object-top">
                @endif
            </a>
        @elseif ($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $name }}" loading="lazy" class="h-full w-full object-cover object-top">
        @endif
        @unless ($imageUrl)
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center text-primary-800/40">
                <i data-lucide="package" width="28" height="28"></i>
            </div>
        @endunless
        @if ($product)
            @include('Components.buyer.wishlist-heart')
        @endif
        @if (!empty($discountBadge))
            <span class="pointer-events-none absolute left-3 top-3 rounded bg-primary-800 px-2 py-1 text-xs font-semibold text-white">{{ $discountBadge }}</span>
        @endif
        @if (($quickView ?? false) && $url)
            <a href="{{ $url }}" class="absolute bottom-3 left-3 right-3 rounded bg-white px-3 py-2 text-center text-xs font-semibold text-buyer-ink opacity-0 group-hover:opacity-100 focus:opacity-100">QUICK VIEW</a>
        @endif
    </div>
    <div class="buyer-product-card-body flex min-w-0 flex-1 flex-col px-4 pt-3.5 pb-4">
        <h3 class="min-h-[3em] text-sm font-semibold leading-[1.5] text-buyer-ink">
            @if ($url)
                <a href="{{ $url }}" class="line-clamp-2 hover:text-primary-800">{{ $name }}</a>
            @else
                <span class="line-clamp-2">{{ $name }}</span>
            @endif
        </h3>
        <p class="mt-1 truncate text-xs text-buyer-ink-soft">
            @if ($seller)
                <a href="{{ route('buyer.products.index', ['seller' => $seller->id]) }}" class="hover:text-buyer-ink">By {{ $seller->business_name }}</a>
            @else
                By Zefanya
            @endif
        </p>
        <p class="mt-1 text-xs text-buyer-ink-soft">
            @if ($product && $product->reviews_count)
                {{ number_format((float) $product->reviews_avg_rating, 1) }}/5 ({{ $product->reviews_count }} reviews)
            @else
                No reviews yet
            @endif
        </p>
        <div class="mt-2.5 mb-3 flex items-center justify-between gap-2">
            <div class="min-w-0">
                <span class="text-[15px] font-bold text-buyer-ink">{{ $product?->formattedPriceRange() ?? '?' }}</span>
                @if (!empty($oldPrice))
                    <span class="text-xs text-price-muted line-through">{{ $oldPrice }}</span>
                @endif
            </div>
            @if ($product)
                <span class="shrink-0 text-[11px] text-buyer-ink-soft">{{ $product->buyerIsOutOfStock() ? 'Out of stock' : ($product->buyerIsLowStock() ? 'Low stock' : 'In stock') }}</span>
            @endif
        </div>
        @if ($product)
            @if ($chooseVariant && ! $product->buyerIsOutOfStock())
                <a href="{{ $url }}" class="mt-auto inline-flex min-h-10 w-full items-center justify-center rounded-lg bg-primary-800 px-3 py-2 text-xs font-semibold text-white hover:bg-primary-700">Add to Cart</a>
            @else
                <button type="button" data-product-id="{{ $product->id }}" @disabled($product->buyerIsOutOfStock())
                        class="add-to-cart-btn mt-auto min-h-10 w-full rounded-lg bg-primary-800 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-50">{{ $product->buyerIsOutOfStock() ? 'Out of Stock' : 'Add to Cart' }}</button>
            @endif
        @endif
    </div>
</article>
