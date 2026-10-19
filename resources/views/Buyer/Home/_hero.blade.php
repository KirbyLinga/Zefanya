<div class="mx-auto w-full max-w-[1600px] px-2 pt-2 sm:px-4">
  <section data-home-hero role="region" aria-roledescription="carousel" aria-label="Featured at Zefanya"
           tabindex="0" class="relative overflow-hidden rounded-[22px] bg-linear-to-r from-[#60494b] to-[#bd8390] text-white">
    <div data-hero-track class="flex h-[550px] transition-transform duration-700 ease-in-out motion-reduce:transition-none sm:h-[480px]">
      @foreach ($heroSlides as $slide)
        <div data-hero-slide role="group" aria-roledescription="slide" aria-label="{{ $loop->iteration }} of {{ count($heroSlides) }}"
             @if (!$loop->first) hidden inert @endif
             class="relative h-full w-full shrink-0">
          <div class="mx-auto grid h-full max-w-[1440px] grid-rows-[190px_1fr] px-12 pb-14 pt-6 sm:grid-cols-[60%_40%] sm:grid-rows-1 sm:px-16 sm:pb-12 sm:pt-10 lg:px-20">
            <div class="order-2 flex min-w-0 items-center text-center sm:order-1 sm:text-left">
              <div class="w-full max-w-[560px]">
                <p class="mb-3 font-sans text-xs font-semibold text-white/85 sm:text-sm">{{ $slide['heading'] }}</p>
                <h1 class="break-words font-serif text-[34px] font-semibold leading-[1.05] text-white sm:text-[46px] lg:text-[58px]">{{ $slide['heading'] === 'New arrivals' ? 'Fresh finds for every day' : $slide['heading'] }}</h1>
                <p class="mt-4 line-clamp-2 font-sans text-sm leading-relaxed text-white/90 sm:text-base">{{ $slide['text'] }}</p>
                <a href="{{ $slide['url'] }}" class="mt-6 inline-flex max-w-full items-center justify-center rounded-full bg-[#f7d6d0] px-7 py-3.5 font-sans text-sm font-semibold text-[#3a2529] transition-colors hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">{{ $slide['button'] }}</a>
              </div>
            </div>
            <div class="order-1 flex min-w-0 items-start justify-center sm:order-2 sm:pt-2">
              <div class="h-[170px] w-[170px] overflow-hidden rounded-full bg-[#ead1d5] sm:h-[240px] sm:w-[240px] lg:h-[280px] lg:w-[280px]">
                @if ($slide['image'])
                  <img src="{{ $slide['image'] }}" alt="{{ $slide['alt'] }}" width="{{ $slide['width'] }}" height="{{ $slide['height'] }}"
                       loading="{{ $loop->first ? 'eager' : 'lazy' }}" @if ($loop->first) fetchpriority="high" @endif
                       class="h-full w-full object-cover object-top">
                @endif
              </div>
            </div>
          </div>
        </div>
      @endforeach
    </div>
    @if (count($heroSlides) > 1)
      @foreach (['prev' => 'Previous', 'next' => 'Next'] as $direction => $label)
        <button data-hero-{{ $direction }} type="button" aria-label="{{ $label }} slide"
                class="absolute top-1/2 z-10 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-[#3a2529] transition-colors hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white {{ $direction === 'prev' ? 'left-3 sm:left-4' : 'right-3 sm:right-4' }}">
          <i data-lucide="{{ $direction === 'prev' ? 'chevron-left' : 'chevron-right' }}" width="20" height="20"></i>
        </button>
      @endforeach
      <div class="absolute bottom-3 left-0 right-0 flex items-center justify-center gap-1">
        @foreach ($heroSlides as $slide)
          <button data-hero-dot type="button" aria-label="Show slide {{ $loop->iteration }}" aria-current="{{ $loop->first ? 'true' : 'false' }}"
                  class="group flex h-8 items-center justify-center rounded-full px-1 focus-visible:outline-2 focus-visible:outline-white">
            <span class="h-2.5 w-2.5 rounded-full bg-white/50 transition-all duration-300 group-aria-[current=true]:w-[30px] group-aria-[current=true]:bg-white motion-reduce:transition-none"></span>
          </button>
        @endforeach
      </div>
      <p data-hero-status class="sr-only" aria-live="polite" aria-atomic="true"></p>
    @endif
  </section>
</div>
