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

    {{-- Carousel track — --cat-per-view drives card widths via CSS calc() --}}
    <div class="[--cat-per-view:5] relative w-full overflow-hidden max-lg:[--cat-per-view:4] max-sm:[--cat-per-view:2]"
         id="catCarousel">
      <div class="flex items-stretch gap-4 transition-transform duration-[450ms] will-change-transform" id="catTrack">
        @foreach([
          ['icon' => 'book-open',    'label' => 'Books and Media'],
          ['icon' => 'heart-pulse',  'label' => 'Health and Beauty'],
          ['icon' => 'mountain',     'label' => 'Sports and Outdoors'],
          ['icon' => 'flower-2',     'label' => 'Home and Garden'],
          ['icon' => 'baby',         'label' => 'Kids and Baby'],
          ['icon' => 'shirt',        'label' => "Men's Apparel"],
          ['icon' => 'sparkles',     'label' => "Women's Apparel"],
          ['icon' => 'smartphone',   'label' => 'Electronics and Gadgets'],
          ['icon' => 'paw-print',    'label' => 'Pet Supplies'],
          ['icon' => 'utensils',     'label' => 'Food and Gourmet'],
          ['icon' => 'car',          'label' => 'Automotive & Motorcycle'],
          ['icon' => 'sofa',         'label' => 'Furniture and Office'],
          ['icon' => 'gem',          'label' => 'Jewelry and Watches'],
          ['icon' => 'pencil-ruler', 'label' => 'Office and School'],
        ] as $cat)
          <a href="{{ route('buyer.categories.index') }}"
             class="group basis-[calc((100%_-_((var(--cat-per-view)_-_1)_*_16px))_/_var(--cat-per-view))] relative flex min-h-[120px] min-w-[110px] shrink-0 flex-col items-center justify-center rounded-2xl border border-buyer-line bg-white px-4 py-6 text-center transition-[flex,padding,margin,box-shadow,border-color] duration-300 hover:-mx-1 hover:grow-[0.3] hover:border-primary-700 hover:px-5 hover:py-8 hover:shadow-[0_12px_28px_-8px_rgba(58,37,41,0.20)]">
            <div class="mb-2 inline-flex h-14 w-14 items-center justify-center rounded-full bg-secondary-100 transition-transform duration-300 group-hover:scale-110">
              <i data-lucide="{{ $cat['icon'] }}" width="24" height="24" class="text-primary-800"></i>
            </div>
            <span class="mt-2 w-full text-center text-[11px] font-semibold uppercase leading-[1.4] tracking-[1.1px] text-buyer-ink transition-[font-size] duration-300 group-hover:mt-3 group-hover:text-[12px]">
              {{ $cat['label'] }}
            </span>
          </a>
        @endforeach
      </div>
    </div>

    {{-- Prev / dots / Next --}}
    <div class="mt-6 flex w-full items-center justify-center gap-4">
      <button type="button"
              class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full border border-buyer-line bg-white shadow-[0_2px_8px_rgba(58,37,41,0.08)] transition-colors hover:bg-secondary-100"
              id="catPrev" aria-label="Previous categories">
        <i data-lucide="chevron-left" width="18" height="18" class="text-primary-800"></i>
      </button>
      <div class="flex items-center gap-2.5" id="catDots" role="tablist" aria-label="Category pages"></div>
      <button type="button"
              class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full border border-buyer-line bg-white shadow-[0_2px_8px_rgba(58,37,41,0.08)] transition-colors hover:bg-secondary-100"
              id="catNext" aria-label="Next categories">
        <i data-lucide="chevron-right" width="18" height="18" class="text-primary-800"></i>
      </button>
    </div>
  </section>

  {{-- ═══════════════════════════════════════════════════════════════════════
       FLASH SALE
       ═══════════════════════════════════════════════════════════════════════ --}}
  <section id="flash-sale" class="py-6 sm:py-10">
    {{-- Section header with animated sale badge --}}
    <div class="mb-5 flex items-center gap-3">
      <h2 class="font-serif text-2xl text-buyer-ink sm:text-[28px]">Flash Sale</h2>
      <div class="flex items-center gap-1.5">
        <span class="rounded bg-buyer-ink px-2.5 py-0.5 text-[10px] font-bold tracking-[0.08em] text-white uppercase">On</span>
        <span class="rounded bg-primary-200 px-2.5 py-0.5 text-[10px] font-bold tracking-[0.08em] text-primary-800 uppercase">Sale</span>
      </div>
      {{-- Close / dismiss icon (decorative, matches mockup's ✕ in top-right) --}}
      <button type="button" class="ml-auto rounded-full p-1 text-neutral-400 transition-colors hover:text-buyer-ink" aria-label="Dismiss flash sale">
        <i data-lucide="x" width="16" height="16"></i>
      </button>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
      {{-- 4 product cards --}}
      <div class="grid grid-cols-2 gap-4 sm:col-span-2 lg:col-span-4 lg:grid-cols-4">
        @php $discountBadges = ['40%', '28%', '35%', '20%']; @endphp
        @forelse ($flashProducts as $i => $product)
          @include('Components.buyer.product-card', [
              'product'       => $product,
              'discountBadge' => $discountBadges[$i] ?? null,
              'url'           => route('buyer.products.show', $product->id),
          ])
        @empty
          @foreach (range(0, 3) as $i)
            @include('Components.buyer.product-card', [
                'discountBadge' => $discountBadges[$i] ?? null,
            ])
          @endforeach
        @endforelse
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
    <script>
    (function () {
        var carousel = document.getElementById('catCarousel');
        var track    = document.getElementById('catTrack');
        var dotsWrap = document.getElementById('catDots');
        if (!carousel || !track || !dotsWrap) return;

        var page = 0;

        function perView() {
            var v = getComputedStyle(carousel).getPropertyValue('--cat-per-view');
            var n = parseInt(v, 10);
            return n > 0 ? n : 5;
        }

        function pageCount() {
            return Math.ceil(track.children.length / perView());
        }

        function go(idx) {
            var pages = pageCount();
            page = Math.max(0, Math.min(idx, pages - 1));

            var card  = track.children[0];
            if (!card) return;
            var cardW = card.getBoundingClientRect().width;
            var gap   = parseFloat(getComputedStyle(track).gap) || 0;
            var step  = (cardW + gap) * perView();
            var max   = Math.max(0, track.scrollWidth - carousel.clientWidth);
            var shift = Math.min(page * step, max);

            track.style.transform = 'translateX(-' + shift + 'px)';

            Array.prototype.forEach.call(dotsWrap.children, function (d, i) {
                d.classList.toggle('w-[22px]',      i === page);
                d.classList.toggle('bg-primary-800', i === page);
                d.classList.toggle('bg-buyer-line',  i !== page);
            });
        }

        function setup() {
            var pages = pageCount();
            if (page >= pages) page = pages - 1;

            dotsWrap.innerHTML = '';
            for (var i = 0; i < pages; i++) {
                (function (idx) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'h-2 w-2 cursor-pointer rounded-full border-0 p-0 transition-[background-color,width] duration-300 bg-buyer-line'
                        + (idx === page ? ' !w-[22px] !bg-primary-800' : '');
                    b.setAttribute('aria-label', 'Category page ' + (idx + 1));
                    b.addEventListener('click', function () { go(idx); });
                    dotsWrap.appendChild(b);
                })(i);
            }
            go(page);
        }

        document.getElementById('catPrev').addEventListener('click', function () {
            go(page === 0 ? pageCount() - 1 : page - 1);
        });
        document.getElementById('catNext').addEventListener('click', function () {
            go(page === pageCount() - 1 ? 0 : page + 1);
        });

        var t;
        window.addEventListener('resize', function () {
            clearTimeout(t);
            t = setTimeout(setup, 150);
        });

        setup();
    })();
    </script>
@endpush
