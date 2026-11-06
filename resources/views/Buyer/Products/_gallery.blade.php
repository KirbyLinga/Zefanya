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
    <button type="button" id="pdp-image-open" aria-label="Enlarge product photo" aria-haspopup="dialog" aria-controls="pdp-image-modal" @disabled(! $mainImg) class="block aspect-[4/5] w-full cursor-zoom-in focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-buyer-ink disabled:cursor-default">
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
    </button>

    {{-- Maximize icon — bottom-right --}}
    <button
      type="button"
      id="pdp-maximize"
      class="absolute right-4 bottom-4 flex h-9 w-9 items-center justify-center rounded-full bg-white/80 text-buyer-ink shadow-sm backdrop-blur-sm transition-colors hover:bg-white"
      aria-label="View full image"
      aria-haspopup="dialog"
      aria-controls="pdp-image-modal"
      @disabled(! $mainImg)
    >
      <i data-lucide="maximize-2" width="16" height="16"></i>
    </button>
  </div>

  @if (count($galleryImages) > 1)
    <div class="mx-auto w-full max-w-[480px]" role="group" aria-label="Product photos">
      <p class="mb-2 text-xs text-buyer-ink-soft">{{ count($galleryImages) }} photos — select a photo to enlarge it and view other angles</p>
      <div class="flex gap-2 overflow-x-auto pb-2">
        @foreach ($galleryImages as $imageUrl)
          <button type="button"
                  class="js-pdp-thumb h-20 w-20 shrink-0 overflow-hidden rounded-xl border-2 bg-secondary-100 p-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-buyer-ink {{ $loop->first ? 'border-buyer-ink' : 'border-transparent' }}"
                  data-src="{{ $imageUrl }}"
                  aria-label="View photo {{ $loop->iteration }} of {{ count($galleryImages) }}"
                  aria-haspopup="dialog" aria-controls="pdp-image-modal"
                  aria-pressed="{{ $loop->first ? 'true' : 'false' }}">
            <img src="{{ $imageUrl }}" alt="{{ $product->name }} — photo {{ $loop->iteration }}" loading="lazy" class="h-full w-full object-contain">
          </button>
        @endforeach
      </div>
    </div>
  @endif
</div>

<dialog id="pdp-image-modal" aria-labelledby="pdp-image-modal-title" class="backdrop:bg-black/70 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-5xl overflow-y-auto rounded-2xl border border-buyer-line bg-white p-3 text-buyer-ink shadow-xl sm:p-5">
  <div class="mb-3 flex items-center justify-between gap-3">
    <h2 id="pdp-image-modal-title" class="min-w-0 truncate font-serif text-lg font-semibold">{{ $product->name }} photos</h2>
    <button type="button" id="pdp-image-close" autofocus aria-label="Close photo viewer" class="flex size-11 shrink-0 items-center justify-center rounded-full border border-buyer-line hover:bg-secondary-100 focus-visible:outline-2 focus-visible:outline-buyer-ink">
      <i data-lucide="x" width="20" height="20" aria-hidden="true"></i>
    </button>
  </div>
  <img id="pdp-modal-img" alt="" class="h-[60dvh] w-full rounded-xl bg-secondary-100 object-contain sm:h-[70dvh]">
  <p id="pdp-image-error" role="status" hidden class="mt-3 text-center text-sm text-buyer-ink-soft">This photo could not be loaded. Try another photo or close the viewer.</p>
  <div class="mt-3 flex items-center justify-between gap-3">
    <button type="button" id="pdp-image-prev" aria-label="Previous photo" class="flex min-h-11 items-center gap-1 rounded-lg border border-buyer-line px-3 text-sm font-semibold hover:bg-secondary-100 focus-visible:outline-2 focus-visible:outline-buyer-ink">
      <i data-lucide="chevron-left" width="18" height="18" aria-hidden="true"></i><span>Previous</span>
    </button>
    <p id="pdp-image-counter" role="status" aria-live="polite" aria-atomic="true" class="text-center text-xs text-buyer-ink-soft"></p>
    <button type="button" id="pdp-image-next" aria-label="Next photo" class="flex min-h-11 items-center gap-1 rounded-lg border border-buyer-line px-3 text-sm font-semibold hover:bg-secondary-100 focus-visible:outline-2 focus-visible:outline-buyer-ink">
      <span>Next</span><i data-lucide="chevron-right" width="18" height="18" aria-hidden="true"></i>
    </button>
  </div>
</dialog>
