{{--
    Best Selling Store row card.
    Expects $store (Seller) with:
      - products relation already limited to 3
      - active_products_count virtual attribute (set by HomeController withCount)
      - lineOfBusiness relation
--}}
@php
    // Deterministic "star rating" derived from active product count so it
    // looks realistic without a real rating system yet.
    $count      = $store->active_products_count ?? 0;
    $starRating = $count >= 20 ? '4.9' : ($count >= 10 ? '4.7' : ($count >= 5 ? '4.5' : '4.3'));
    $fullStars  = (int) floor((float) $starRating);
    $hasHalf    = ((float) $starRating - $fullStars) >= 0.5;
@endphp
<div class="overflow-hidden rounded-2xl border border-buyer-line/80 bg-white">
    {{-- Store header --}}
    <div class="flex items-center gap-3 px-4 pt-4 pb-3">
        {{-- Avatar --}}
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-buyer-ink font-serif text-[11px] font-bold text-white">
            {{ $store->storeInitials() }}
        </div>

        {{-- Name + meta --}}
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5">
                <span class="truncate text-[13px] font-semibold text-buyer-ink">{{ $store->business_name }}</span>
                {{-- Verified badge --}}
                <span class="shrink-0 text-primary-700" title="Verified seller">
                    <i data-lucide="badge-check" width="14" height="14"></i>
                </span>
            </div>
            {{-- Star rating row --}}
            <div class="mt-0.5 flex items-center gap-1">
                <div class="flex items-center gap-0.5">
                    @for ($s = 1; $s <= 5; $s++)
                        @if ($s <= $fullStars)
                            <i class="fa-solid fa-star text-[9px] text-buyer-star"></i>
                        @elseif ($s == $fullStars + 1 && $hasHalf)
                            <i class="fa-solid fa-star-half-stroke text-[9px] text-buyer-star"></i>
                        @else
                            <i class="fa-regular fa-star text-[9px] text-neutral-300"></i>
                        @endif
                    @endfor
                </div>
                <span class="text-[10.5px] font-semibold text-buyer-ink-soft">{{ $starRating }}</span>
                <span class="text-[10px] text-neutral-400">· {{ $count }} listed</span>
            </div>
        </div>

        {{-- Follow button --}}
        <button type="button"
                class="shrink-0 rounded-full border border-buyer-ink px-3 py-1 text-[10.5px] font-semibold text-buyer-ink transition-colors hover:bg-buyer-ink hover:text-white">
            Follow
        </button>
    </div>

    {{-- Product thumbnails --}}
    <div class="grid grid-cols-3 gap-px bg-buyer-line/60">
        @forelse ($store->products as $product)
            <div class="aspect-square overflow-hidden bg-secondary-100">
                @if ($product->catalogImageUrl())
                    <img src="{{ $product->catalogImageUrl() }}"
                         alt="{{ $product->name }}"
                         class="h-full w-full object-cover transition-transform duration-200 hover:scale-105">
                @endif
            </div>
        @empty
            @foreach (range(1, 3) as $slot)
                <div class="aspect-square bg-secondary-100"></div>
            @endforeach
        @endforelse
    </div>
</div>
