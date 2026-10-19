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
        class="h-full w-full object-cover object-top transition-opacity duration-200 {{ $mainImg ? '' : 'opacity-0' }}"
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


</div>
