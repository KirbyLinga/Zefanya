<div class="mx-auto max-w-[1440px] px-4 pt-4 sm:px-8 lg:px-16">
  <section data-home-hero role="region" aria-roledescription="carousel" aria-label="Featured at Zefanya"
           tabindex="0" class="relative overflow-hidden rounded-2xl bg-[#4a3036] text-white">
    <div data-hero-track class="flex h-[540px] transition-transform duration-700 ease-in-out motion-reduce:transition-none md:h-[440px]">
      @foreach ($heroSlides as $slide)
        <div data-hero-slide role="group" aria-roledescription="slide" aria-label="{{ $loop->iteration }} of {{ count($heroSlides) }}"
             @if (!$loop->first) hidden inert @endif
             class="h-full w-full shrink-0">
          <div class="grid h-full grid-rows-[200px_minmax(0,1fr)] md:grid-cols-2 md:grid-rows-1">
            <div class="order-2 flex min-w-0 items-center px-6 pb-20 pt-6 md:order-1 md:px-12 md:pb-20 md:pt-8 lg:px-14">
              <div class="w-full max-w-[520px]">
                <p class="mb-3 font-sans text-xs font-semibold uppercase tracking-[1.6px] text-[#f7d6d0]">{{ $slide['heading'] }}</p>
                <h{{ $loop->first ? 1 : 2 }} class="line-clamp-2 break-words font-serif text-[30px] font-semibold leading-[1.1] text-white md:text-[38px] lg:text-[46px]">{{ $slide['title'] }}</h{{ $loop->first ? 1 : 2 }}>
                <p class="mt-3 line-clamp-2 font-sans text-sm leading-relaxed text-white/85 md:text-base">{{ $slide['text'] }}</p>
                <a href="{{ $slide['url'] }}" class="mt-6 inline-flex max-w-full items-center justify-center gap-2 rounded-full bg-[#f7d6d0] px-6 py-3 font-sans text-sm font-semibold text-[#3a2529] no-underline transition-colors hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                  {{ $slide['button'] }}
                  <i data-lucide="arrow-right" width="16" height="16" aria-hidden="true"></i>
                </a>
              </div>
            </div>
            <div class="relative order-1 min-h-0 overflow-hidden bg-[#5f4047] md:order-2">
              @if ($slide['image'])
                <img src="{{ $slide['image'] }}" alt="{{ $slide['alt'] }}" width="{{ $slide['width'] }}" height="{{ $slide['height'] }}"
                     loading="{{ $loop->first ? 'eager' : 'lazy' }}" @if ($loop->first) fetchpriority="high" @endif
                     class="absolute inset-0 h-full w-full object-cover">
              @else
                <div class="flex h-full items-center justify-center text-white/40">
                  <i data-lucide="shopping-bag" width="64" height="64" aria-hidden="true"></i>
                </div>
              @endif
            </div>
          </div>
        </div>
      @endforeach
    </div>
    @if (count($heroSlides) > 1)
      {{-- Controls sit on the plain panel so they never cover the product photo. --}}
      <div class="absolute bottom-5 left-6 z-10 flex items-center gap-2 md:left-12 lg:left-14">
        <button data-hero-prev type="button" aria-label="Previous slide"
                class="flex h-10 w-10 items-center justify-center rounded-full border border-white/40 text-white transition-colors hover:bg-white hover:text-[#3a2529] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
          <i data-lucide="chevron-left" width="18" height="18" aria-hidden="true"></i>
        </button>
        <div class="flex items-center gap-1 px-1">
          @foreach ($heroSlides as $slide)
            <button data-hero-dot type="button" aria-label="Show slide {{ $loop->iteration }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"
                    class="group flex h-8 items-center justify-center rounded-full px-1 focus-visible:outline-2 focus-visible:outline-white">
              <span class="h-2 w-2 rounded-full bg-white/50 transition-all duration-300 group-aria-[current=true]:w-6 group-aria-[current=true]:bg-white motion-reduce:transition-none"></span>
            </button>
          @endforeach
        </div>
        <button data-hero-next type="button" aria-label="Next slide"
                class="flex h-10 w-10 items-center justify-center rounded-full border border-white/40 text-white transition-colors hover:bg-white hover:text-[#3a2529] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
          <i data-lucide="chevron-right" width="18" height="18" aria-hidden="true"></i>
        </button>
      </div>
      <p data-hero-status class="sr-only" aria-live="polite" aria-atomic="true"></p>
    @endif
  </section>
</div>
