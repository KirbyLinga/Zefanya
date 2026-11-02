{{--
    Best Selling Store row card — compact layout.
    Expects $store (Seller) with:
      - products relation already limited to 3
      - active_products_count virtual attribute (set by HomeController withCount)
      - lineOfBusiness relation
    Optional $following: whether the signed-in buyer follows this store.
--}}
@php
    $count = $store->active_products_count ?? 0;
    $starRating = $store->reviews_count ? number_format((float) $store->reviews_avg_rating, 1) : null;
    $fullStars  = (int) floor((float) $starRating);
    $hasHalf    = ((float) $starRating - $fullStars) >= 0.5;
    $storeUrl   = route('buyer.stores.show', $store);
@endphp
<div class="buyer-product-card h-full bg-white overflow-hidden">
    {{-- Store header (compact) --}}
    <div class="flex items-center gap-2 px-4 py-3 pb-2">
        <a href="{{ $storeUrl }}"
           class="group flex min-w-0 flex-1 items-center gap-2.5 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
           aria-label="View {{ $store->business_name }} store">
            {{-- Avatar (smaller) --}}
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-buyer-ink font-serif text-[10px] font-bold text-white transition-opacity group-hover:opacity-90">
                {{ $store->storeInitials() }}
            </div>

            {{-- Name + meta (compact) --}}
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-1">
                    <span class="truncate text-[12px] font-semibold text-buyer-ink transition-colors group-hover:text-primary-800">{{ $store->business_name }}</span>
                    {{-- Verified badge --}}
                    <span class="shrink-0 text-primary-700" title="Verified seller">
                        <i data-lucide="badge-check" width="12" height="12"></i>
                    </span>
                </div>
                {{-- Star rating row (compact) --}}
                <div class="mt-0.5 flex items-center gap-1 text-[9px]">
                    <div class="flex items-center gap-0.5">
                        @for ($s = 1; $s <= 5; $s++)
                            @if ($s <= $fullStars)
                                <i class="fa-solid fa-star text-[8px] text-buyer-star"></i>
                            @elseif ($s == $fullStars + 1 && $hasHalf)
                                <i class="fa-solid fa-star-half-stroke text-[8px] text-buyer-star"></i>
                            @else
                                <i class="fa-regular fa-star text-[8px] text-neutral-300"></i>
                            @endif
                        @endfor
                    </div>
                    @if ($starRating)
                        <span class="font-semibold text-buyer-ink-soft">{{ $starRating }}/5</span>
                        <span class="text-neutral-400">({{ $store->reviews_count }})</span>
                    @else
                        <span class="text-neutral-400">No reviews</span>
                    @endif
                </div>
            </div>
        </a>

        {{-- Follow button (smaller) --}}
        @auth('buyer')
            <form method="POST" action="{{ route('buyer.stores.follow', $store) }}" class="shrink-0">
                @csrf
                <button type="submit" aria-pressed="{{ ($following ?? false) ? 'true' : 'false' }}"
                        class="rounded-full border border-buyer-ink px-2.5 py-0.5 text-[9px] font-semibold text-buyer-ink transition-colors hover:bg-buyer-ink hover:text-white">
                    {{ ($following ?? false) ? 'Unfollow' : 'Follow' }}
                </button>
            </form>
        @else
            <button type="button" onclick="window.openLoginModal?.()" title="Log in to follow this store"
                    class="shrink-0 rounded-full border border-buyer-ink px-2.5 py-0.5 text-[9px] font-semibold text-buyer-ink transition-colors hover:bg-buyer-ink hover:text-white">
                Follow
            </button>
        @endauth
    </div>

    {{-- Product thumbnails (grid 3 columns) --}}
    <div class="grid grid-cols-3 gap-px bg-buyer-line/60">
        @forelse ($store->products as $product)
            <div class="relative aspect-square overflow-hidden bg-secondary-100">
                <a href="{{ $storeUrl }}"
                   class="block h-full w-full"
                   aria-label="View {{ $store->business_name }} store">
                    @if ($thumbnail = $product->catalogImageUrl(requireExistingFile: false, thumbnail: true))
                        <img src="{{ $thumbnail }}"
                             alt="{{ $product->name }}"
                             loading="lazy" decoding="async"
                             class="h-full w-full object-cover object-top transition-transform duration-300 hover:scale-105">
                    @endif
                </a>
                @include('Components.buyer.wishlist-heart')
            </div>
        @empty
            @foreach (range(1, 3) as $slot)
                <div class="aspect-square bg-secondary-100"></div>
            @endforeach
        @endforelse
    </div>

    {{-- Store footer (compact CTA) --}}
    <div class="px-4 py-2.5 text-center border-t border-buyer-line/50">
        <a href="{{ $storeUrl }}"
           class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-primary-800 hover:text-primary-900 transition-colors">
            Visit Store
            <i data-lucide="arrow-right" width="11" height="11"></i>
        </a>
    </div>
</div>
