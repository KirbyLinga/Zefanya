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
  <section id="categories" class="py-10 sm:py-12">
    <div class="mb-6 flex items-center justify-between">
      <h2 class="font-serif text-2xl text-buyer-ink sm:text-[28px]">Explore Categories</h2>
      <a href="{{ route('buyer.categories.index') }}"
         class="inline-flex items-center gap-1.5 text-[12px] font-semibold uppercase tracking-[1.6px] text-primary-800 transition-colors hover:text-primary-900">
        View All
        <i data-lucide="arrow-right" width="12" height="12"></i>
      </a>
    </div>

    {{-- A native scroller, so swipe, trackpad and keyboard all work and the browser does the animating.
         --cat-per-view drives card widths via CSS calc(); the padding leaves room for the hover lift and shadow. --}}
    <div class="[--cat-per-view:5] -mx-1 -mb-5 -mt-1.5 flex snap-x snap-mandatory scroll-px-1 items-stretch gap-4 overflow-x-auto px-1 pb-5 pt-1.5 [scrollbar-width:none] max-lg:[--cat-per-view:4] max-sm:[--cat-per-view:2] [&::-webkit-scrollbar]:hidden"
         id="catCarousel" tabindex="0" role="region" aria-label="Categories">
      @foreach ($categories as $cat)
        <a href="{{ route('buyer.categories.show', $cat->id) }}"
           class="group flex min-h-[120px] min-w-[110px] shrink-0 basis-[calc((100%_-_((var(--cat-per-view)_-_1)_*_16px))_/_var(--cat-per-view))] snap-start flex-col items-center justify-center rounded-2xl border border-buyer-line bg-white px-4 py-6 text-center no-underline transition-[transform,box-shadow,border-color] duration-200 ease-out hover:-translate-y-1 hover:border-primary-700 hover:shadow-[0_12px_24px_-10px_rgba(58,37,41,0.20)] focus-visible:border-primary-700 motion-reduce:transition-none motion-reduce:hover:translate-y-0">
          <div class="mb-2 inline-flex h-14 w-14 items-center justify-center rounded-full bg-secondary-100 transition-transform duration-200 ease-out group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100">
            <i data-lucide="{{ $cat->icon }}" width="24" height="24" class="text-primary-800"></i>
          </div>
          <span class="mt-2 w-full text-center text-[11px] font-semibold uppercase leading-[1.4] tracking-[1.1px] text-buyer-ink">
            {{ $cat->label }}
          </span>
        </a>
      @endforeach
    </div>

    {{-- Prev / dots / Next --}}
    <div class="mt-6 flex w-full items-center justify-center gap-3" id="catControls">
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
      {{-- Close / dismiss icon (decorative, matches mockup's ✕ in top-right) --}}
      <button type="button" class="ml-auto rounded-full p-1 text-neutral-400 transition-colors hover:text-buyer-ink" aria-label="Dismiss flash sale">
        <i data-lucide="x" width="16" height="16"></i>
      </button>
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

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
      {{-- 4 product cards --}}
      <div class="grid grid-cols-2 gap-4 sm:col-span-2 lg:col-span-4 lg:grid-cols-4">
        @foreach ($flashProducts as $product)
          @include('Components.buyer.product-card', [
              'product'       => $product,
              'url'           => route('buyer.products.show', $product->id),
          ])
        @endforeach
      </div>

      {{-- Promo aside — matches "PREMIUM BRAND / The Zefanya Mall Privilege" card --}}
      <aside class="flex min-h-[280px] flex-col justify-between overflow-hidden rounded-2xl bg-linear-[160deg] from-primary-200 via-secondary-100 to-cream p-5 lg:min-h-full">
        <div>
          <span class="mb-2 inline-block rounded bg-buyer-ink px-2 py-0.5 text-[9px] font-bold tracking-[0.1em] text-white uppercase">Premium Brand</span>
          <p class="mt-1 font-serif text-[20px] leading-snug text-buyer-ink">The Zefanya Mall Privilege</p>
          <p class="mt-2 text-[12px] leading-relaxed text-buyer-ink-soft">
            100% guaranteed authentic sourcing from global premium brands.
          </p>
        </div>
        <div>
          <div class="mb-3 rounded-xl bg-white/70 px-3 py-2.5">
            <p class="text-[10px] font-bold tracking-[0.06em] text-buyer-ink-soft uppercase">Cashback 15%</p>
            <p class="text-[10px] text-buyer-ink-soft">on your first premium order</p>
          </div>
          <a href="#for-you" class="inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-buyer-ink hover:underline">
            Explore Mall
            <i data-lucide="arrow-right" width="13" height="13"></i>
          </a>
        </div>
      </aside>
    </div>
  </section>
  @endif

  {{-- ═══════════════════════════════════════════════════════════════════════
       TODAY'S FOR YOU + BEST SELLING STORES
       ═══════════════════════════════════════════════════════════════════════ --}}
  <section id="for-you" class="py-8 sm:py-12">
    {{-- Header row: title + tabs + View All --}}
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
      <h2 class="font-serif text-2xl text-buyer-ink sm:text-[28px]">Todays For You!</h2>
      <div class="flex flex-wrap items-center gap-x-5 gap-y-2" role="tablist" aria-label="Product filters">
        <button type="button"
          class="js-home-tab active text-[12.5px] font-semibold text-neutral-400 transition-colors [&.active]:text-buyer-ink [&.active]:underline [&.active]:decoration-buyer-ink [&.active]:underline-offset-8"
          aria-selected="true">Best Seller</button>
        <button type="button"
          class="js-home-tab text-[12.5px] font-semibold text-neutral-400 transition-colors [&.active]:text-buyer-ink [&.active]:underline [&.active]:decoration-buyer-ink [&.active]:underline-offset-8"
          aria-selected="false">Keep It Off</button>
        <button type="button"
          class="js-home-tab text-[12.5px] font-semibold text-neutral-400 transition-colors [&.active]:text-buyer-ink [&.active]:underline [&.active]:decoration-buyer-ink [&.active]:underline-offset-8"
          aria-selected="false">Special Discount</button>
        <button type="button"
          class="js-home-tab text-[12.5px] font-semibold text-neutral-400 transition-colors [&.active]:text-buyer-ink [&.active]:underline [&.active]:decoration-buyer-ink [&.active]:underline-offset-8"
          aria-selected="false">Official Store</button>
        <button type="button"
          class="js-home-tab text-[12.5px] font-semibold text-neutral-400 transition-colors [&.active]:text-buyer-ink [&.active]:underline [&.active]:decoration-buyer-ink [&.active]:underline-offset-8"
          aria-selected="false">Greatest Product</button>
        <a href="{{ route('buyer.products.index') }}"
           class="text-[12.5px] font-semibold text-neutral-400 transition-colors hover:text-buyer-ink">
          View All
        </a>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
      {{-- Product grid --}}
      <div class="grid grid-cols-2 gap-4 md:grid-cols-3">
        @forelse ($forYouProducts as $product)
          @include('Components.buyer.product-card', [
              'product' => $product,
              'url'     => route('buyer.products.show', $product->id),
          ])
        @empty
          @foreach (range(1, 6) as $slot)
            @include('Components.buyer.product-card')
          @endforeach
        @endforelse
      </div>

      {{-- Best Selling Stores sidebar --}}
      <aside class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
          <h3 class="font-serif text-[18px] text-buyer-ink">Best Selling Store</h3>
        </div>
        @forelse ($stores as $store)
          @include('Components.buyer.store-card', ['store' => $store])
        @empty
          <div class="rounded-2xl border border-dashed border-buyer-line bg-white p-5 text-[13px] text-neutral-500">
            Approved stores with live products will appear here.
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
       TRUST BADGES — why shop with us
       ═══════════════════════════════════════════════════════════════════════ --}}

@endsection

@push('scripts')
    @vite(['resources/js/buyer/home.js'])
@endpush
