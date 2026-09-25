{{--
    PDP Gallery partial.
    Expects: $galleryImages (array of URLs or nulls), $product (Product)
--}}
@php
    $mainImg   = $galleryImages[0] ?? null;
    $thumbImgs = array_slice($galleryImages, 0, 5);
    // Pad to exactly 5 so layout stays stable
    while (count($thumbImgs) < 5) { $thumbImgs[] = null; }
@endphp

<div class="flex flex-col gap-3">

  {{-- Main image --}}
    <div class="relative mx-auto w-full max-w-[480px] overflow-hidden rounded-3xl bg-secondary-100">
    <div class="aspect-[4/3] max-h-[900px]">
      <img
        id="pdp-main-img"
        src="{{ $mainImg ?? '' }}"
        alt="{{ $product->name }}"
        class="h-full w-full object-cover transition-opacity duration-200 {{ $mainImg ? '' : 'opacity-0' }}"
      />
      {{-- Placeholder shown when no image --}}
      @if (!$mainImg)
        <div class="absolute inset-0 flex items-center justify-center bg-secondary-100 text-primary-300">
          <i data-lucide="package" width="64" height="64"></i>
        </div>
      @endif
    </div>

    {{-- Editor's badge chip — top-left --}}
    <span class="absolute top-4 left-4 rounded-full bg-white/90 px-3 py-1 font-sans text-[10.5px] font-semibold tracking-[0.06em] text-buyer-ink shadow-sm backdrop-blur-sm">
      Editor's Exclusive
    </span>

    {{-- Maximize icon — bottom-right --}}
    <button
      type="button"
      id="pdp-maximize"
      class="absolute right-4 bottom-4 flex h-9 w-9 items-center justify-center rounded-full bg-white/80 text-buyer-ink shadow-sm backdrop-blur-sm transition-colors hover:bg-white"
      aria-label="View full image"
    >
      <i data-lucide="maximize-2" width="16" height="16"></i>
    </button>
  </div>

    {{-- Thumbnail strip --}}
  <div class="flex gap-2 overflow-x-auto pb-1" role="list" aria-label="Product images">
    @foreach ($thumbImgs as $i => $thumb)
      <button
        type="button"
        class="pdp-thumb js-pdp-thumb shrink-0 overflow-hidden rounded-xl border-2 transition-all duration-150
          {{ $i === 0 ? 'border-buyer-ink' : 'border-transparent' }}
          hover:border-primary-400"
        data-src="{{ $thumb ?? '' }}"
        data-index="{{ $i }}"
        aria-label="Image {{ $i + 1 }}"
        aria-pressed="{{ $i === 0 ? 'true' : 'false' }}"
        role="listitem"
      >
        <div class="h-[72px] w-[60px] bg-secondary-100 sm:h-[80px] sm:w-[68px]">
          @if ($thumb)
            <img src="{{ $thumb }}" alt="Thumbnail {{ $i + 1 }}" class="h-full w-full object-cover">
          @else
            <div class="flex h-full w-full items-center justify-center text-primary-200">
              <i data-lucide="image" width="20" height="20"></i>
            </div>
          @endif
        </div>
      </button>
    @endforeach
  </div>

  {{-- Product description (under image, left side) --}}
  @if ($product->description)
    <p class="mt-4 text-[14px] leading-relaxed text-buyer-ink-soft">
      {{ $product->description }}
    </p>
  @endif

  {{-- Ratings summary line --}}
  <div class="mt-4 flex items-center gap-3 text-[12px] text-buyer-ink-soft">
    <div class="flex items-center gap-0.5">
      @for ($s = 1; $s <= 5; $s++)
        <i data-lucide="star"
           class="h-[12px] w-[12px] {{ $s <= floor($rating) ? 'text-primary-500' : 'text-neutral-200' }}"></i>
      @endfor
    </div>
    <span class="font-semibold text-buyer-ink">{{ number_format($rating, 1) }}</span>
    <span class="text-neutral-300">·</span>
    <span class="text-neutral-400">{{ number_format($reviewCount) }} Reviews</span>
    <span class="text-neutral-300">·</span>
    <span class="text-neutral-400">{{ number_format($soldCount) }} Sold</span>
  </div>

</div>
