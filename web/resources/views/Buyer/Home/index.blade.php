@extends('Buyer.Layouts.app')

@section('title', 'Home')

@section('content')
<div class="bg-cream">

{{-- Server-side flash (e.g. bounced here from the not-yet-built chat pages) --}}
@if (session('info'))
  <div class="mx-auto mt-4 max-w-[1440px] px-4 sm:px-8 lg:px-16">
    <div class="rounded-xl border border-buyer-line bg-white px-4 py-3 text-[13px] font-medium text-buyer-ink">
      {{ session('info') }}
    </div>
  </div>
@endif

@include('Buyer.Home._hero')

<div class="mx-auto max-w-[1440px] px-4 sm:px-8 lg:px-16">

  {{-- ═══════════════════════════════════════════════════════════════════════
       CATEGORY CAROUSEL  (mirrors landing page, buyer tokens)
       ═══════════════════════════════════════════════════════════════════════ --}}
  <section id="categories" class="py-6 sm:py-8">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="font-serif text-2xl text-buyer-ink sm:text-[28px]">Explore Categories</h2>
      <a href="{{ route('buyer.categories.index') }}"
         class="inline-flex items-center gap-1.5 text-[12px] font-semibold uppercase tracking-[1.6px] text-primary-800 transition-colors hover:text-primary-900">
        View All
        <i data-lucide="arrow-right" width="12" height="12"></i>
      </a>
    </div>

    {{-- A native scroller, so swipe, trackpad and keyboard all work and the browser does the animating.
         --cat-per-view drives card widths via CSS calc(); the padding leaves room for the hover lift and shadow. --}}
    <div class="[--cat-per-view:5] -mx-1 -mb-4 -mt-1 flex snap-x snap-mandatory scroll-px-1 items-stretch gap-4 overflow-x-auto px-1 pb-4 pt-1 [scrollbar-width:none] max-lg:[--cat-per-view:4] max-sm:[--cat-per-view:2] [&::-webkit-scrollbar]:hidden"
         id="catCarousel" tabindex="0" role="region" aria-label="Categories">
      @foreach ($categories as $cat)
        <a href="{{ route('buyer.categories.show', $cat->id) }}"
           class="group relative isolate flex aspect-[4/5] min-w-[110px] shrink-0 basis-[calc((100%_-_((var(--cat-per-view)_-_1)_*_16px))_/_var(--cat-per-view))] snap-start flex-col justify-end overflow-hidden rounded-2xl bg-primary-800 p-4 no-underline transition-[transform,box-shadow] duration-200 ease-out hover:-translate-y-1 hover:shadow-[0_12px_24px_-10px_rgba(58,37,41,0.45)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800 motion-reduce:transition-none motion-reduce:hover:translate-y-0 sm:p-5">
          {{-- The label names the card, so the photo is decorative. The solid background keeps the label readable if it fails to load. --}}
          {{-- Not lazy: the arrows should reveal cards already loaded, so only the priority differs. --}}
          <img src="{{ $cat->image_url }}" srcset="{{ $cat->image_srcset }}" sizes="(max-width: 640px) 50vw, (max-width: 1024px) 25vw, 250px"
               alt="" width="640" height="800" fetchpriority="{{ $loop->index < 5 ? 'high' : 'low' }}" decoding="async"
               class="absolute inset-0 -z-10 h-full w-full object-cover transition-transform duration-500 ease-out group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100">
          <span class="absolute inset-0 -z-10 bg-[linear-gradient(180deg,rgba(58,37,41,0)_45%,rgba(58,37,41,0.2)_62%,rgba(58,37,41,0.68)_78%,rgba(58,37,41,0.9)_100%)]" aria-hidden="true"></span>
          <span class="font-serif text-[17px] leading-[1.25] text-white [text-wrap:balance] sm:text-[19px]">
            {{ $cat->label }}
          </span>
        </a>
      @endforeach
    </div>

    {{-- Prev / dots / Next --}}
    <div class="mt-3 flex w-full items-center justify-center gap-3" id="catControls">
      <button type="button"
              class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full border border-buyer-line bg-white shadow-[0_2px_8px_rgba(58,37,41,0.08)] transition-[background-color,opacity] duration-150 hover:bg-secondary-100 disabled:cursor-default disabled:opacity-40 disabled:hover:bg-white"
              id="catPrev" aria-label="Previous categories">
        <i data-lucide="chevron-left" width="18" height="18" class="text-primary-800"></i>
      </button>
      <div class="flex items-center" id="catDots" role="group" aria-label="Category pages"></div>
      <button type="button"
              class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full border border-buyer-line bg-white shadow-[0_2px_8px_rgba(58,37,41,0.08)] transition-[background-color,opacity] duration-150 hover:bg-secondary-100 disabled:cursor-default disabled:opacity-40 disabled:hover:bg-white"
              id="catNext" aria-label="Next categories">
        <i data-lucide="chevron-right" width="18" height="18" class="text-primary-800"></i>
      </button>
    </div>
  </section>

  {{-- ═══════════════════════════════════════════════════════════════════════
       FLASH SALE
       ═══════════════════════════════════════════════════════════════════════ --}}
  @if ($flashProducts->isNotEmpty())
  <section id="flash-sale" class="py-6 sm:py-10">
    {{-- Section header with animated sale badge --}}
    <div class="mb-5 flex items-center gap-3">
      <h2 class="font-serif text-2xl text-buyer-ink sm:text-[28px]">Flash Sale</h2>
      <div class="flex items-center gap-1.5">
        <span class="rounded bg-buyer-ink px-2.5 py-0.5 text-[10px] font-bold tracking-[0.08em] text-white uppercase">On</span>
        <span class="rounded bg-primary-200 px-2.5 py-0.5 text-[10px] font-bold tracking-[0.08em] text-primary-800 uppercase">Sale</span>
      </div>
      @php
          $flashEndsAt = $flashProducts->map(fn ($product) => $product->ongoingSale?->ends_at)->filter()->sort()->first();
      @endphp
      @if ($flashEndsAt)
        <p id="flash-countdown" class="text-sm font-semibold text-primary-800" data-ends="{{ $flashEndsAt->toIso8601String() }}">Ends in <span data-flash-remaining>--:--:--</span></p>
      @endif
    </div>
    @if ($flashEndsAt ?? null)
      <script>
        (function () {
          const root = document.getElementById('flash-countdown');
          const node = root?.querySelector('[data-flash-remaining]');
          if (!root || !node) return;
          const end = new Date(root.dataset.ends).getTime();
          const tick = () => {
            const left = Math.max(0, end - Date.now());
            const hours = Math.floor(left / 3600000);
            const minutes = Math.floor((left % 3600000) / 60000);
            const seconds = Math.floor((left % 60000) / 1000);
            const pad = (value) => String(value).padStart(2, '0');
            node.textContent = pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
          };
          tick();
          setInterval(tick, 1000);
        })();
      </script>
    @endif

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
      @foreach ($flashProducts as $product)
        @include('Components.buyer.product-card', [
            'product'       => $product,
            'url'           => route('buyer.products.show', $product->id),
        ])
      @endforeach
    </div>
  </section>
  @endif

  @include('Buyer.Home._product-section', [
      'sectionId' => 'on-sale',
      'title' => 'On Sale',
      'products' => $onSaleProducts,
      'viewAllUrl' => route('buyer.products.index', ['on_sale' => 1]),
  ])

  @include('Buyer.Home._product-section', [
      'sectionId' => 'new-arrivals',
      'title' => 'New Arrivals',
      'products' => $newArrivals,
      'viewAllUrl' => route('buyer.products.index', ['sort' => 'newest']),
  ])

  @include('Buyer.Home._product-section', [
      'sectionId' => 'trending',
      'title' => 'Trending Products',
      'products' => $trendingProducts,
  ])

  {{-- ═══════════════════════════════════════════════════════════════════════
       TODAY'S FOR YOU + BEST SELLING STORES
       ═══════════════════════════════════════════════════════════════════════ --}}
  <section id="for-you" class="py-8 sm:py-12">
    {{-- Header row: title + View All --}}
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
      <h2 class="font-serif text-2xl text-buyer-ink sm:text-[28px]">Todays For You!</h2>
      <a href="{{ route('buyer.products.index') }}"
         class="text-[12.5px] font-semibold text-neutral-400 transition-colors hover:text-buyer-ink">
        View All
      </a>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
      {{-- Product grid --}}
      <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
        @forelse ($forYouProducts as $product)
          @include('Components.buyer.product-card', [
              'product' => $product,
              'url'     => route('buyer.products.show', $product->id),
          ])
        @empty
          @foreach (range(1, 8) as $slot)
            @include('Components.buyer.product-card')
          @endforeach
        @endforelse
      </div>

      {{-- Best Selling Stores sidebar --}}
      <aside class="flex flex-col gap-3 self-start">
        <div class="flex items-center justify-between">
          <h3 class="font-serif text-[18px] text-buyer-ink">Best Selling Store</h3>
        </div>
        @forelse ($stores as $store)
          @include('Components.buyer.store-card', ['store' => $store, 'compact' => true, 'following' => in_array($store->id, $followedStoreIds, true)])
        @empty
          <div class="rounded-2xl border border-dashed border-buyer-line bg-white p-5 text-[13px] text-neutral-500">
            Stores will appear here once they have delivered orders.
          </div>
        @endforelse
      </aside>
    </div>

    {{-- Load More --}}
    <div class="mt-10 flex justify-center pb-6">
      <a href="{{ route('buyer.products.index') }}"
         class="inline-flex items-center gap-2 rounded-full border border-buyer-ink px-10 py-3 text-[11.5px] font-semibold tracking-[0.09em] text-buyer-ink uppercase transition-colors hover:bg-buyer-ink hover:text-white">
        Load More Products
      </a>
    </div>
  </section>

  {{-- ═══════════════════════════════════════════════════════════════════════
       EXPLORE STORES
       ═══════════════════════════════════════════════════════════════════════ --}}
  @if ($exploreStores->isNotEmpty())
  <section id="explore-stores" class="py-6 sm:py-10">
    <h2 class="mb-5 font-serif text-2xl text-buyer-ink sm:text-[28px]">Explore Stores</h2>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      @foreach ($exploreStores as $store)
        @include('Components.buyer.store-card', ['store' => $store, 'following' => in_array($store->id, $followedStoreIds, true)])
      @endforeach
    </div>
  </section>
  @endif

  {{-- ═══════════════════════════════════════════════════════════════════════
       TRUST BADGES — why shop with us
       ═══════════════════════════════════════════════════════════════════════ --}}
  <section class="py-8 sm:py-12">
    <h2 class="mb-8 text-center font-serif text-2xl text-buyer-ink sm:text-[28px]">Why Shop with Zefanya?</h2>
    
    <div class="grid grid-cols-1 gap-6 md:grid-cols-3 lg:gap-8">
      {{-- Cash on Delivery: the only payment method --}}
      <div class="flex flex-col items-center text-center">
        <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary-100">
          <i data-lucide="banknote" width="28" height="28" class="text-primary-800"></i>
        </div>
        <h3 class="mb-2 font-serif text-[18px] font-semibold text-buyer-ink">Cash on Delivery</h3>
        <p class="text-[13px] leading-relaxed text-buyer-ink-soft">
          Pay in cash when your order arrives. No card or online payment details needed.
        </p>
      </div>

      {{-- Delivery Tracking --}}
      <div class="flex flex-col items-center text-center">
        <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary-100">
          <i data-lucide="truck" width="28" height="28" class="text-primary-800"></i>
        </div>
        <h3 class="mb-2 font-serif text-[18px] font-semibold text-buyer-ink">Order Tracking</h3>
        <p class="text-[13px] leading-relaxed text-buyer-ink-soft">
          Follow your order's status and tracking number from placement to delivery.
        </p>
      </div>

      {{-- Verified Sellers --}}
      <div class="flex flex-col items-center text-center">
        <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary-100">
          <i data-lucide="store" width="28" height="28" class="text-primary-800"></i>
        </div>
        <h3 class="mb-2 font-serif text-[18px] font-semibold text-buyer-ink">Verified Sellers</h3>
        <p class="text-[13px] leading-relaxed text-buyer-ink-soft">
          Every seller submits an ID and business permit for approval by our team. Something wrong with an order? You can request a return.
        </p>
      </div>
    </div>
  </section>

</div>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/buyer/home.js'])
@endpush
