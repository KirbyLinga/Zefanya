{{-- One product card for landing, home, shop/list, category, search, wishlist and related products.
     Layout stays: image + heart overlay, body stack, full-width Add to Cart.
     Body info follows the richer catalog guide: store, title, price, rating/sold, stock count. --}}
@php
    $product = $product ?? null;
    $url = $product ? ($url ?? route('buyer.products.show', $product->id)) : null;
    $name = $product?->name ?? 'Product coming soon';
    $imageUrl = $product?->catalogImageUrl(requireExistingFile: false);
    $seller = $product?->seller;
    $chooseVariant = $product && ($product->variants_count > 1 || $product->has_option_variants);
    $stockQty = $product ? $product->buyerStockQuantity() : 0;
    $pricing = $product?->buyerPrice();
    $oldPrice = $pricing?->formattedRegular();
    $discountBadge = $pricing?->badge();
    $soldUnits = (int) ($product?->sold_units ?? 0);
    $reviewCount = (int) ($product?->reviews_count ?? 0);
    $rating = $reviewCount > 0
        ? number_format((float) $product->reviews_avg_rating, 1)
        : '0.0';
    $stockLabel = null;
    $stockClass = 'text-buyer-ink-soft';
    if ($product) {
        if ($product->buyerIsOutOfStock()) {
            $stockLabel = 'Out of stock';
            $stockClass = 'text-red-600';
        } elseif ($product->buyerIsLowStock()) {
            $stockLabel = $stockQty.' in stock';
            $stockClass = 'text-amber-700';
        } else {
            $stockLabel = $stockQty.' in stock';
            $stockClass = 'text-tertiary-700';
        }
    }
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
        @if($product?->isOnVacation())<span class="absolute bottom-3 left-3 rounded bg-primary-800 px-3 py-1 text-xs font-semibold text-white">On vacation</span>@endif
        @if (!empty($discountBadge))
            <span class="pointer-events-none absolute left-3 top-3 rounded bg-primary-800 px-2 py-1 text-xs font-semibold text-white">{{ $discountBadge }}</span>
        @endif
        @if (($quickView ?? false) && $url)
            <a href="{{ $url }}" class="absolute bottom-3 left-3 right-3 rounded bg-white px-3 py-2 text-center text-xs font-semibold text-buyer-ink opacity-0 group-hover:opacity-100 focus:opacity-100">QUICK VIEW</a>
        @endif
    </div>
    <div class="buyer-product-card-body flex min-w-0 flex-1 flex-col px-4 pt-3.5 pb-4">
        <p class="truncate text-[10px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">
            @if ($seller)
                <a href="{{ route('buyer.products.index', ['seller' => $seller->id]) }}" class="hover:text-buyer-ink">{{ $seller->business_name }}</a>
            @else
                Zefanya
            @endif
        </p>
        <h3 class="mt-1 min-h-[2.75em] text-sm font-semibold leading-[1.4] text-buyer-ink">
            @if ($url)
                <a href="{{ $url }}" class="line-clamp-2 hover:text-primary-800">{{ $name }}</a>
            @else
                <span class="line-clamp-2">{{ $name }}</span>
            @endif
        </h3>

        <div class="mt-2.5 border-t border-buyer-line pt-2.5">
            @if($product?->buyerIsOutOfStock())
                <span class="mb-1 block text-xs font-semibold text-red-600">Sold Out</span>
            @endif
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                <span class="text-[15px] font-bold text-buyer-ink">{{ $product ? ($pricing?->formatted() ?? 'Unavailable') : '?' }}</span>
                @if (!empty($oldPrice))
                    <span class="text-xs text-price-muted line-through">{{ $oldPrice }}</span>
                @endif
            </div>
            @if ($product)
                <p class="mt-1.5 flex flex-wrap items-center gap-x-1.5 text-[11px] text-buyer-ink-soft">
                    <span class="inline-flex items-center gap-1" aria-label="Average rating {{ $rating }} from {{ $reviewCount }} {{ $reviewCount === 1 ? 'review' : 'reviews' }}">
                        <i class="fa-solid fa-star text-[10px] text-buyer-star" aria-hidden="true"></i>
                        <span class="font-semibold text-buyer-ink">{{ $rating }}</span>
                        <span>({{ number_format($reviewCount) }})</span>
                    </span>
                    <span aria-hidden="true">·</span>
                    <span>{{ number_format($soldUnits) }} sold</span>
                </p>
                <p class="mt-1.5 inline-flex items-center gap-1.5 text-[11px] font-semibold {{ $stockClass }}">
                    <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
                    {{ $stockLabel }}
                </p>
                @if ($product->ongoingSale?->flash_quantity !== null)
                    <p class="mt-1 text-[11px] font-semibold text-primary-800">{{ app(\App\Services\Shared\OfferPricing::class)->slotsLeft($product->ongoingSale) }} flash left</p>
                @endif
            @endif
        </div>

        @if ($product)
            @if ($chooseVariant && ! $product->buyerIsOutOfStock() && ! $product->isOnVacation())
                <a href="{{ $url }}" class="mt-auto inline-flex min-h-10 w-full items-center justify-center rounded-lg bg-primary-800 px-3 py-2 text-xs font-semibold text-white hover:bg-primary-700">Add to Cart</a>
            @else
                <button type="button" data-product-id="{{ $product->id }}" @disabled($product->buyerIsOutOfStock() || $product->isOnVacation())
                        class="add-to-cart-btn mt-auto min-h-10 w-full rounded-lg bg-primary-800 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-50">{{ $product->buyerIsOutOfStock() ? 'Out of Stock' : 'Add to Cart' }}</button>
            @endif
        @endif
    </div>
</article>
