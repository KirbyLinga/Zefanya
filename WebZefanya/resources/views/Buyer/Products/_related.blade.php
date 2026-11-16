{{--
    PDP Related products carousel partial.
    Expects: $related (Collection of Product with images/seller loaded)
    Reuses Components.buyer.product-card with the optional `url` prop.
--}}
<section class="mt-12 pb-10" aria-label="Complete the Atelier Look">
  <div class="mb-5 flex items-center justify-between">
    <div>
      <p class="text-[10.5px] font-semibold uppercase tracking-[0.12em] text-neutral-400">Complete Your Look</p>
      <h2 class="font-serif text-[22px] font-semibold text-buyer-ink sm:text-[26px]">Complete the Atelier Look</h2>
    </div>
    <div class="flex gap-2">
      <button type="button" id="related-prev"
              class="flex h-9 w-9 items-center justify-center rounded-full border border-buyer-line bg-white text-buyer-ink transition-colors hover:bg-secondary-100"
              aria-label="Previous products">
        <i data-lucide="chevron-left" width="17" height="17"></i>
      </button>
      <button type="button" id="related-next"
              class="flex h-9 w-9 items-center justify-center rounded-full border border-buyer-line bg-white text-buyer-ink transition-colors hover:bg-secondary-100"
              aria-label="Next products">
        <i data-lucide="chevron-right" width="17" height="17"></i>
      </button>
    </div>
  </div>

  {{-- Scroll-snap track --}}
  <div class="overflow-hidden" id="related-viewport">
    <div class="flex gap-4 transition-transform duration-300 ease-out will-change-transform" id="related-track">
      @forelse ($related as $rProduct)
        <div class="w-[calc((100%-48px)/4)] shrink-0 max-lg:w-[calc((100%-32px)/3)] max-md:w-[calc((100%-16px)/2)] max-sm:w-[calc((100%-8px)/1.4)]">
          @include('Components.buyer.product-card', [
              'product' => $rProduct,
              'url'     => route('buyer.products.show', $rProduct),
          ])
        </div>
      @empty
        @foreach (range(1, 4) as $slot)
          <div class="w-[calc((100%-48px)/4)] shrink-0">
            @include('Components.buyer.product-card')
          </div>
        @endforeach
      @endforelse
    </div>
  </div>
</section>
