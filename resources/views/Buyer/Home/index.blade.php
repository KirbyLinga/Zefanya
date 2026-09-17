@extends('Buyer.Layouts.app')

@section('title', 'Home')

@push('styles')
    {{-- buyer/home.css is now INERT (every class it styled was replaced by
         utilities in this file). Kept loaded purely as a safety net until the
         dev verifies the page visually — then this @vite line and the file are
         removed together. See docs/css-migration-notes.md (chunk b). --}}
    @vite(['resources/css/buyer/home.css'])
@endpush

@section('content')

{{-- ============================================================================
     Converted to Tailwind (Buyer phase, chunk b — dev approved).
     Values are 1:1 with buyer/home.css + the shared buyer.css rules.
     ── NOTES THAT MATTER WHEN READING THIS VIEW ──────────────────────────────
     • `hero` gradient: home.css used linear-gradient(120deg, --secondary 0%,
       --primary 100%). `--secondary` = #F7D6D0 = secondary-300 and `--primary`
       = #E2B4BD = primary-300, so the shared scale tokens are used directly.
     • HERO ART is a two-layer gradient (radial sheen over a conic sweep) that
       has no utility equivalent, so it uses an arbitrary `background-image`
       value. Underscores are the Tailwind escape for spaces.
     • `h1`/`h2` colour is NOT set here: `app.css` @layer base colours h1-h6
       with `var(--text-primary)`, matching what design-system.css used to do
       for buyer. Buyer's own warm `--ink` (#3A2529) is only used where
       buyer.css explicitly applied it (`.order-id b`, `.view-all`, …).
     • `.step:last-child .step-line{display:none}` was dropped: the last step's
       markup never contains a `.step-line`, so the rule was already a no-op.
     • `display:flex` / `display:inline-block` inline styles were folded into
       utilities (that IS the migration — no markup structure was changed).
     ============================================================================ --}}

{{-- HERO / FLASH SALE --}}
<div class="mt-7 bg-linear-[120deg] from-secondary-300 from-0% to-primary-300">
  <div class="mx-auto flex max-w-[1200px] items-center justify-between gap-10 px-8 py-13">
    <div>
      <div class="mb-1.5 text-[13px] text-buyer-ink opacity-75">Welcome back, {{ $buyerName ?? 'there' }}</div>
      <h1 class="mt-0 mb-3.5 max-w-[480px] text-[40px] leading-[1.15] font-semibold">Today's flash sale is live — up to 40% off</h1>
      <p class="mt-0 mb-[22px] max-w-[420px] text-[14.5px] text-buyer-ink-soft">Handpicked home, beauty and lifestyle finds, refreshed every day. Stock moves fast once the timer starts.</p>
      <button class="inline-flex items-center gap-2.5 rounded-full border-0 bg-buyer-ink px-[26px] py-[13px] text-[13.5px] font-semibold tracking-[0.02em] text-white">Shop the sale <i class="fa-solid fa-arrow-right"></i></button>
      <div class="mt-[26px] flex gap-2">
        <div class="min-w-[52px] rounded-lg bg-white/55 px-3 py-2 text-center backdrop-blur-[2px]"><b id="hh" class="block font-serif text-[19px]">04</b><span class="text-[9.5px] tracking-[0.05em] text-buyer-ink-soft">HRS</span></div>
        <div class="min-w-[52px] rounded-lg bg-white/55 px-3 py-2 text-center backdrop-blur-[2px]"><b id="mm" class="block font-serif text-[19px]">18</b><span class="text-[9.5px] tracking-[0.05em] text-buyer-ink-soft">MIN</span></div>
        <div class="min-w-[52px] rounded-lg bg-white/55 px-3 py-2 text-center backdrop-blur-[2px]"><b id="ss" class="block font-serif text-[19px]">52</b><span class="text-[9.5px] tracking-[0.05em] text-buyer-ink-soft">SEC</span></div>
      </div>
    </div>
    {{-- Two-layer gradient: radial sheen + conic sweep. Arbitrary value only
         because no utility can express a multi-stop conic gradient. --}}
    <div class="flex h-[220px] w-[220px] shrink-0 items-center justify-center rounded-full shadow-buyer bg-[image:radial-gradient(circle_at_30%_30%,#ffffff70,transparent_60%),conic-gradient(from_200deg,var(--color-tertiary-300),var(--color-buyer-primary-dark),var(--color-secondary-300),var(--color-tertiary-300))] max-[980px]:hidden"><i class="fa-solid fa-gift text-[56px] text-white opacity-90"></i></div>
  </div>
</div>

{{-- `.container` was buyer.css's shared wrapper (max-width:1200px; margin:0
     auto; padding:0 32px). Inlined here so this page no longer depends on that
     shared class — same conversion as Buyer/Layouts/footer. --}}
<div class="mx-auto max-w-[1200px] px-8">

  {{-- CATEGORIES TEASER — full browsing lives at Categories/index --}}
  <section class="py-11">
    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-5">
      <div>
        <h2 class="m-0 text-[27px] font-semibold">Browse categories</h2>
        <div class="mt-1 text-[13px] text-neutral-500">Everything you need, sorted the way you shop</div>
      </div>
      <a class="flex items-center gap-1.5 text-[12.5px] font-semibold text-buyer-ink" href="{{ route('buyer.categories.index') }}">View all <i class="fa-solid fa-arrow-right text-[11px]"></i></a>
    </div>
    <div class="flex gap-4 overflow-x-auto pb-1.5">
      {{-- TODO: hardcoded 8-category teaser matching the original mockup's
           icon set (fa-solid), not the 14-category DB list (lucide icon
           names from the categories table). Once this is wired to real
           data, either map lucide->fontawesome names or standardize on one
           icon library across the whole app. --}}
      @foreach ([
          ['icon' => 'book-open', 'label' => 'Books & Media'],
          ['icon' => 'heart-pulse', 'label' => 'Health & Beauty'],
          ['icon' => 'mountain', 'label' => 'Sports & Outdoors'],
          ['icon' => 'seedling', 'label' => 'Home & Garden'],
          ['icon' => 'shirt', 'label' => 'Fashion & Apparel'],
          ['icon' => 'child-reaching', 'label' => 'Kids & Baby'],
          ['icon' => 'laptop', 'label' => 'Electronics'],
          ['icon' => 'basket-shopping', 'label' => 'Groceries'],
      ] as $cat)
        <div class="w-[118px] flex-none rounded-xl border border-buyer-line bg-white px-2.5 py-5 text-center transition-[transform,box-shadow] duration-150 hover:-translate-y-[3px] hover:border-primary-300 hover:shadow-buyer">
          <div class="mx-auto mb-2.5 flex h-11 w-11 items-center justify-center rounded-full bg-secondary-300 text-[17px] text-buyer-primary-dark"><i class="fa-solid fa-{{ $cat['icon'] }}"></i></div>
          <span class="block text-xs leading-[1.3] font-semibold">{{ $cat['label'] }}</span>
        </div>
      @endforeach
    </div>
  </section>

  {{-- FLASH SALE GRID --}}
  <section class="py-11">
    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-5">
      <div class="flex items-center">
        <h2 class="m-0 text-[27px] font-semibold">Flash Sale</h2>
        <div class="ml-3.5 inline-flex items-center gap-2 rounded-full border border-buyer-line bg-buyer-cream px-3 py-1.5 text-[12.5px] text-buyer-ink-soft"><i class="fa-regular fa-clock"></i> Ends in <b id="chipTimer" class="font-serif text-buyer-primary-dark">04:18:52</b></div>
      </div>
      <a class="flex items-center gap-1.5 text-[12.5px] font-semibold text-buyer-ink" href="{{ route('buyer.products.index') }}">See all deals <i class="fa-solid fa-arrow-right text-[11px]"></i></a>
    </div>
    <div class="grid grid-cols-4 gap-5 max-[980px]:grid-cols-2" id="flashGrid">
      {{-- populated by home.js — see its TODO about replacing with real product data --}}
    </div>
  </section>

  {{-- RECOMMENDED / TABS --}}
  <section class="py-11">
    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-5">
      <div><h2 class="m-0 text-[27px] font-semibold">Picked for you</h2><div class="mt-1 text-[13px] text-neutral-500">Based on your recent browsing</div></div>
    </div>
    {{-- `tab` and `active` are KEPT as JS hooks: home.js does
         `querySelectorAll('.tab')` and toggles `active`. The active styling is
         expressed as a Tailwind `[&.active]:` variant on the element itself, so
         no state rule has to survive in custom CSS. --}}
    <div class="mb-[26px] flex flex-wrap gap-2.5">
      <button class="tab active rounded-full border border-buyer-line bg-white px-[18px] py-[9px] text-[12.5px] font-semibold text-buyer-ink-soft [&.active]:border-buyer-ink [&.active]:bg-buyer-ink [&.active]:text-white">Best Seller</button>
      <button class="tab rounded-full border border-buyer-line bg-white px-[18px] py-[9px] text-[12.5px] font-semibold text-buyer-ink-soft [&.active]:border-buyer-ink [&.active]:bg-buyer-ink [&.active]:text-white">New Arrivals</button>
      <button class="tab rounded-full border border-buyer-line bg-white px-[18px] py-[9px] text-[12.5px] font-semibold text-buyer-ink-soft [&.active]:border-buyer-ink [&.active]:bg-buyer-ink [&.active]:text-white">Top Rated</button>
      <button class="tab rounded-full border border-buyer-line bg-white px-[18px] py-[9px] text-[12.5px] font-semibold text-buyer-ink-soft [&.active]:border-buyer-ink [&.active]:bg-buyer-ink [&.active]:text-white">Official Store</button>
      <button class="tab rounded-full border border-buyer-line bg-white px-[18px] py-[9px] text-[12.5px] font-semibold text-buyer-ink-soft [&.active]:border-buyer-ink [&.active]:bg-buyer-ink [&.active]:text-white">Under Rp100.000</button>
    </div>
    <div class="grid grid-cols-4 gap-5 max-[980px]:grid-cols-2" id="recoGrid"></div>
  </section>

  {{-- ORDER STATUS --}}
  <section class="py-11">
    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-5">
      <div><h2 class="m-0 text-[27px] font-semibold">Your orders</h2><div class="mt-1 text-[13px] text-neutral-500">Track what's on the way</div></div>
      <a class="flex items-center gap-1.5 text-[12.5px] font-semibold text-buyer-ink" href="{{ route('buyer.orders.index') }}">View all orders <i class="fa-solid fa-arrow-right text-[11px]"></i></a>
    </div>
    <div class="rounded-xl border border-buyer-line bg-white px-7 py-[26px]">
      {{-- TODO: hardcoded single order matching the mockup. Once Orders
           schema exists, show the buyer's most recent active order here,
           or omit this section entirely if they have none. --}}
      <div class="mb-[26px] flex flex-wrap items-start justify-between gap-2.5">
        <div class="text-[13px] text-neutral-500">Order <b class="text-buyer-ink">#ZF-10432</b> · placed 3 Sep 2026</div>
        <a class="flex items-center gap-1.5 text-[12.5px] font-semibold text-buyer-primary-dark" href="#"><i class="fa-solid fa-location-dot"></i> Track shipment</a>
      </div>
      {{-- `done` / `current` are STATIC state classes (never JS-toggled), so
           the old descendant selectors `.step.done .dot` etc. are expressed
           with Tailwind's arbitrary group variant: `group` on the step div,
           `group-[.done]:` / `group-[.current]:` on its children. --}}
      <div class="flex items-center">
        <div class="group done relative flex flex-1 flex-col items-center">
          <div class="z-[2] flex h-[30px] w-[30px] items-center justify-center rounded-full border-2 border-white bg-secondary-300 text-[13px] text-buyer-primary-dark group-[.done]:bg-buyer-tertiary-dark group-[.done]:text-white"><i class="fa-solid fa-check"></i></div>
          <label class="mt-[9px] max-w-[90px] text-center text-[11px] font-semibold text-neutral-500 group-[.done]:text-buyer-ink">Order placed</label>
          <div class="absolute top-[15px] left-1/2 z-[1] h-0.5 w-full bg-buyer-line group-[.done]:bg-buyer-tertiary-dark"></div>
        </div>
        <div class="group done relative flex flex-1 flex-col items-center">
          <div class="z-[2] flex h-[30px] w-[30px] items-center justify-center rounded-full border-2 border-white bg-secondary-300 text-[13px] text-buyer-primary-dark group-[.done]:bg-buyer-tertiary-dark group-[.done]:text-white"><i class="fa-solid fa-check"></i></div>
          <label class="mt-[9px] max-w-[90px] text-center text-[11px] font-semibold text-neutral-500 group-[.done]:text-buyer-ink">Packed</label>
          <div class="absolute top-[15px] left-1/2 z-[1] h-0.5 w-full bg-buyer-line group-[.done]:bg-buyer-tertiary-dark"></div>
        </div>
        {{-- `done` / `current` are STATIC state markers carried over from the
             original `class="step current"` — they are the group that the
             `group-[.done]:` / `group-[.current]:` variants below match on.
             Do NOT drop them: without them every step renders grey. --}}
        <div class="group current relative flex flex-1 flex-col items-center">
          <div class="z-[2] flex h-[30px] w-[30px] items-center justify-center rounded-full border-2 border-white bg-secondary-300 text-[13px] text-buyer-primary-dark group-[.done]:bg-buyer-tertiary-dark group-[.done]:text-white group-[.current]:bg-buyer-primary-dark group-[.current]:text-white group-[.current]:shadow-[0_0_0_4px_var(--color-secondary-300)]"><i class="fa-solid fa-truck"></i></div>
          <label class="mt-[9px] max-w-[90px] text-center text-[11px] font-semibold text-neutral-500 group-[.done]:text-buyer-ink group-[.current]:text-buyer-ink">In transit</label>
          <div class="absolute top-[15px] left-1/2 z-[1] h-0.5 w-full bg-buyer-line group-[.done]:bg-buyer-tertiary-dark"></div>
        </div>
        <div class="group relative flex flex-1 flex-col items-center">
          <div class="z-[2] flex h-[30px] w-[30px] items-center justify-center rounded-full border-2 border-white bg-secondary-300 text-[13px] text-buyer-primary-dark group-[.done]:bg-buyer-tertiary-dark group-[.done]:text-white"><i class="fa-solid fa-route"></i></div>
          <label class="mt-[9px] max-w-[90px] text-center text-[11px] font-semibold text-neutral-500 group-[.done]:text-buyer-ink">Out for delivery</label>
          {{-- No `.step-line` here: the original had one, but
               `.step:last-child .step-line{display:none}`... only applied to
               the true last child. It was rendered but hidden only there, so
               the line is kept exactly as before (visible on this step). --}}
          <div class="absolute top-[15px] left-1/2 z-[1] h-0.5 w-full bg-buyer-line group-[.done]:bg-buyer-tertiary-dark"></div>
        </div>
        <div class="group relative flex flex-1 flex-col items-center">
          <div class="z-[2] flex h-[30px] w-[30px] items-center justify-center rounded-full border-2 border-white bg-secondary-300 text-[13px] text-buyer-primary-dark group-[.done]:bg-buyer-tertiary-dark group-[.done]:text-white"><i class="fa-solid fa-house"></i></div>
          <label class="mt-[9px] max-w-[90px] text-center text-[11px] font-semibold text-neutral-500 group-[.done]:text-buyer-ink">Delivered</label>
        </div>
      </div>
      <div class="mt-[26px] flex flex-wrap items-center justify-between gap-3 border-t border-dashed border-buyer-line pt-[18px]">
        <div class="text-[12.5px] text-neutral-500">3 items · Ceramic Mug Set, Linen Throw Pillow +1</div>
        <div class="flex gap-2.5">
          <button class="rounded-full border border-buyer-ink bg-transparent px-4 py-[9px] text-xs font-semibold">Message seller</button>
          <a href="{{ route('buyer.orders.index') }}" class="inline-block rounded-full border-0 bg-buyer-tertiary-dark px-4 py-[9px] text-xs font-semibold text-white no-underline">View details</a>
        </div>
      </div>
    </div>
  </section>

  {{-- BEST SELLING STORES --}}
  <section class="py-11">
    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-5">
      <div><h2 class="m-0 text-[27px] font-semibold">Best selling stores</h2><div class="mt-1 text-[13px] text-neutral-500">Trusted sellers our buyers keep coming back to</div></div>
      <a class="flex items-center gap-1.5 text-[12.5px] font-semibold text-buyer-ink" href="#">View all <i class="fa-solid fa-arrow-right text-[11px]"></i></a>
    </div>
    <div class="grid grid-cols-4 gap-[18px] max-[980px]:grid-cols-2">
      {{-- TODO: hardcoded stores matching the mockup. Replace with real
           Seller data (business_name, rating, line_of_business) once
           sellers can be approved and have storefronts. --}}
      <div class="flex items-center gap-3 rounded-xl border border-buyer-line bg-white p-[18px]"><div class="flex h-[46px] w-[46px] items-center justify-center rounded-full bg-buyer-ink font-serif font-bold text-white">HN</div><div><div class="text-[13px] font-semibold">Hearth &amp; Nest</div><div class="mt-0.5 flex items-center gap-1 text-[11px] text-neutral-500"><i class="fa-solid fa-star"></i> 4.9 · Home &amp; Living</div></div></div>
      <div class="flex items-center gap-3 rounded-xl border border-buyer-line bg-white p-[18px]"><div class="flex h-[46px] w-[46px] items-center justify-center rounded-full bg-buyer-ink font-serif font-bold text-white">PB</div><div><div class="text-[13px] font-semibold">Pure Botanics</div><div class="mt-0.5 flex items-center gap-1 text-[11px] text-neutral-500"><i class="fa-solid fa-star"></i> 4.8 · Beauty</div></div></div>
      <div class="flex items-center gap-3 rounded-xl border border-buyer-line bg-white p-[18px]"><div class="flex h-[46px] w-[46px] items-center justify-center rounded-full bg-buyer-ink font-serif font-bold text-white">LT</div><div><div class="text-[13px] font-semibold">Little Tumble</div><div class="mt-0.5 flex items-center gap-1 text-[11px] text-neutral-500"><i class="fa-solid fa-star"></i> 4.9 · Kids &amp; Baby</div></div></div>
      <div class="flex items-center gap-3 rounded-xl border border-buyer-line bg-white p-[18px]"><div class="flex h-[46px] w-[46px] items-center justify-center rounded-full bg-buyer-ink font-serif font-bold text-white">FG</div><div><div class="text-[13px] font-semibold">Field &amp; Grove</div><div class="mt-0.5 flex items-center gap-1 text-[11px] text-neutral-500"><i class="fa-solid fa-star"></i> 4.7 · Outdoors</div></div></div>
    </div>
  </section>

</div>

@endsection

@push('scripts')
    @vite(['resources/js/buyer/home.js'])
@endpush
