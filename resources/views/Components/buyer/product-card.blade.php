{{--
    Buyer catalog card.
    Props (all optional):
      $product        — Product model instance
      $discountBadge  — string e.g. "40%" shown as a red badge top-left; omit to hide
      $url            — string URL to the PDP; when provided the image and title
                        link to the PDP. QV button is hidden; add-to-cart and
                        wishlist use stopPropagation so they never navigate.
                        When omitted (landing page, home grid) the image opens
                        the quick-view modal exactly as before.
--}}
@php
    $product       = $product       ?? null;
    $discountBadge = $discountBadge ?? null;
    $url           = $url           ?? null;
    $name          = $product?->name             ?? 'Product coming soon';
    $price         = $product?->formattedPrice() ?? '₱—';
    $imageUrl      = $product?->catalogImageUrl();
    $productId     = $product?->id;
    $stock         = $product?->stock_quantity   ?? 99;
    // "X available" label shown below the price, matches mockup
    $stockLabel    = $productId ? $stock . ' available' : null;
@endphp
<article class="flex h-full flex-col overflow-hidden rounded-2xl bg-white p-3 shadow-[0_2px_10px_-4px_rgba(58,37,41,0.10)] transition-shadow duration-150 hover:shadow-[0_10px_24px_-8px_rgba(58,37,41,0.18)]">
    <div class="relative">
        {{-- Image: PDP link when $url provided, quick-view button otherwise --}}
        @if ($url)
            <a href="{{ $url }}"
               class="relative block aspect-square w-full overflow-hidden rounded-xl bg-secondary-100"
               aria-label="View {{ $name }}">
                @if ($imageUrl)
                    <img src="{{ $imageUrl }}" alt="{{ $name }}" class="h-full w-full object-cover">
                @else
                    <span class="flex h-full w-full items-center justify-center text-primary-800/40">
                        <i data-lucide="package" width="28" height="28"></i>
                    </span>
                @endif
            </a>
        @else
            <button type="button"
                    class="js-open-qv relative block aspect-square w-full overflow-hidden rounded-xl bg-secondary-100"
                    @if($productId) data-product-id="{{ $productId }}" @endif>
                @if ($imageUrl)
                    <img src="{{ $imageUrl }}" alt="{{ $name }}" class="h-full w-full object-cover">
                @else
                    <span class="flex h-full w-full items-center justify-center text-primary-800/40">
                        <i data-lucide="package" width="28" height="28"></i>
                    </span>
                @endif
            </button>
        @endif

        {{-- Discount badge — top-left, only when $discountBadge is set --}}
        @if ($discountBadge)
            <span class="pointer-events-none absolute top-2 left-2 z-[1] rounded bg-red-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
                {{ $discountBadge }}
            </span>
        @endif

        {{-- Wishlist heart — top-right; stopPropagation prevents PDP link navigation --}}
        <button type="button"
                class="wish-toggle absolute top-2 right-2 z-[1] flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-[12px] text-neutral-400 transition-colors [&.active]:text-buyer-wish"
                aria-label="Save to wishlist"
                onclick="event.stopPropagation(); event.preventDefault();">
            <i class="fa-solid fa-heart"></i>
        </button>
    </div>

    <div class="flex flex-1 flex-col gap-1 pt-3">
        {{-- Title: PDP link when $url provided, plain heading otherwise --}}
        @if ($url)
            <a href="{{ $url }}"
               class="line-clamp-2 min-h-[34px] text-[12.5px] leading-snug font-semibold text-buyer-ink hover:underline hover:underline-offset-2">
                {{ $name }}
            </a>
        @else
            <h3 class="line-clamp-2 min-h-[34px] text-[12.5px] leading-snug font-semibold text-buyer-ink">{{ $name }}</h3>
        @endif

        <div class="flex items-baseline gap-2">
            <span class="text-[14px] font-bold text-buyer-ink">{{ $price }}</span>
        </div>

        @if ($stockLabel)
            <span class="text-[10.5px] text-neutral-400">{{ $stockLabel }}</span>
        @endif

        @if ($productId)
            {{-- stopPropagation so clicking "Add to cart" never follows a $url link --}}
            <button type="button"
                    class="js-add-cart mt-1.5 inline-flex items-center gap-1.5 text-[11px] font-semibold text-buyer-ink transition-colors hover:text-primary-700 hover:underline"
                    data-product-id="{{ $productId }}"
                    onclick="event.stopPropagation();">
                <i class="fa-solid fa-bag-shopping text-[9.5px]"></i> Add to cart
            </button>
        @else
            <span class="mt-1 text-[11px] text-neutral-400">Available soon</span>
        @endif
    </div>
</article>
