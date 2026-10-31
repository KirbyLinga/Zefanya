{{--
    PDP Gallery partial.
    Expects: $galleryImages (array of URLs, only real images)
             $product (Product)
--}}
@php
    $mainImg   = $galleryImages[0] ?? null;
@endphp

<div class="flex flex-col gap-3">

  {{-- Main image --}}
  <div class="relative mx-auto w-full max-w-[480px] overflow-hidden rounded-3xl bg-secondary-100">
    <div class="aspect-[4/5]">
      <img
        id="pdp-main-img"
        src="{{ $mainImg ?? '' }}"
        alt="{{ $product->name }}"
        class="h-full w-full object-contain transition-opacity duration-200 {{ $mainImg ? '' : 'opacity-0' }}"
      />
      {{-- Placeholder shown when no image --}}
      @if (!$mainImg)
        <div class="absolute inset-0 flex items-center justify-center bg-secondary-100 text-primary-300">
          <i data-lucide="package" width="64" height="64"></i>
        </div>
      @endif
    </div>

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

  @if (count($galleryImages) > 1)
    <div class="mx-auto w-full max-w-[480px]" role="group" aria-label="Product photos">
      <p class="mb-2 text-xs text-buyer-ink-soft">{{ count($galleryImages) }} photos — select a photo to view another angle</p>
      <div class="flex gap-2 overflow-x-auto pb-2">
        @foreach ($galleryImages as $imageUrl)
          <button type="button"
                  class="js-pdp-thumb h-20 w-20 shrink-0 overflow-hidden rounded-xl border-2 bg-secondary-100 p-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-buyer-ink {{ $loop->first ? 'border-buyer-ink' : 'border-transparent' }}"
                  data-src="{{ $imageUrl }}"
                  aria-label="View photo {{ $loop->iteration }} of {{ count($galleryImages) }}"
                  aria-pressed="{{ $loop->first ? 'true' : 'false' }}">
            <img src="{{ $imageUrl }}" alt="{{ $product->name }} — photo {{ $loop->iteration }}" loading="lazy" class="h-full w-full object-contain">
          </button>
        @endforeach
      </div>
    </div>
  @endif
</div>
