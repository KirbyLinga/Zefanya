  <!DOCTYPE html>
  <html lang="en">
  <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Zefanya — @yield('title', 'Buyer')</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  {{-- Buyer phase: `design-system.css` DELIBERATELY REMOVED from this array.
      It was compiled UNLAYERED (no @layer in the built asset) and therefore
      BEAT every Tailwind utility on `h1-h6`, `p`, `span`, `label`, `button`,
      `input`, `select`, `textarea` (it forces `color: var(--text-primary)`
      on headings and `color: var(--text-secondary)` + Montserrat on the
      rest). Utilities live in `@layer utilities`, and unlayered normal
      declarations outrank ALL layered ones regardless of specificity — so
      e.g. `<h4 class="text-white">` silently rendered dark.
      Its entire token set (80 tokens, verified) is re-exported by
      `app.css`'s `@layer base :root`, and its element rules are ported into
      `app.css`'s `@layer base` with identical values, so nothing visual is
      lost. No buyer markup uses its component classes (.btn/.input/
      .search-bar/.progress/.nav-pill/.action-btn).
      Same state the landing layout is already in. See
      docs/css-migration-notes.md → "CROSS-CUTTING HAZARD". --}}
  @vite(['resources/css/app.css', 'resources/css/login-modal.css', 'resources/css/buyer/buyer-register-modal.css'])
  @stack('styles')
  <script>
    // Mirror the landing page pattern: ensure .html-body class is present.
    // This is required by login-modal.css, landing.css, auth-pages.css, and
    // buyer-register-modal.css (all use .html-body as a selector ancestor).
    (function () {
      var html = document.documentElement;
      if (html && !html.classList.contains('html-body')) {
        html.classList.add('html-body');
      }
    })();
  </script>
  </head>
  <body>

  <div class="html-body">

  @include('Components.navbar', [
      'variant' => 'buyer',         // dark filled Login / outlined Register (replaces auth-buttons.css)
      'showLoginModal' => true,
      'hideGuestInLogin' => true,   // Hide "Continue as Guest" on buyer pages
      'showRegisterModal' => true,  // Open buyer register modal directly
  ])

  @yield('content')

  @include('Components.login-modal')

  @include('Components.buyer-register-modal')

  @include('Buyer.Layouts.footer')


  {{-- Global overlay widgets — present on every buyer page, not just Home,
      since product cards (which trigger quick-view) and the cart/chat
      launchers live in the navbar/every product grid. --}}

  {{-- ============================================================================
      Buyer phase, chunk c — global overlay widgets converted to Tailwind
      (dev approved). Values are 1:1 with buyer.css (see per-widget map below).
      ── TOGGLE-CLASS CONTRACT (buyer.js owns ALL of these — DO NOT rename
      without updating buyer.js in the same change) ─────────────────────────
        `open`     → cartDrawer + overlay (toggleCart), accountDropdown
                      (toggleAccount/closeAllPanels), qvOverlay
                      (openQuickView/closeQuickView), chatPanel (toggleChat)
        `show`     → toast (showToast, 1800ms timeout)
        `selected` → quick-view .swatch + .size-opt (exclusive within group)
      The `js-*` classes are behaviour hooks only (querySelector targets) and
      are kept verbatim alongside the new utilities.
      NOTE on `display:none` interplay: legacy `.drawer` used
      transform-translateX for its hidden state (always rendered, slides in);
      `.modal-overlay`/`.chat-panel`/`.toast` used display:none ↔ flex with
      their .open/.show classes flipping display. The Tailwind versions below
      preserve exactly that: drawer keeps translate-x-full + .open override
      via `[&.open]:translate-x-0`; overlay keeps opacity-0/pointer-events-none
      + .open override; modal/chat/toast keep hidden + .open/.show override
      via `[&.open]:flex` / `[&.show]:...`. buyer.css is kept loaded as a
      safety net until chunk d (dev verifies visually first).
      ============================================================================ --}}

  {{-- Overlay (behind cart drawer) — legacy .overlay/.overlay.open --}}
  <div class="js-overlay pointer-events-none fixed inset-0 z-[60] bg-buyer-ink/35 opacity-0 transition-opacity duration-200 [&.open]:pointer-events-auto [&.open]:opacity-100" id="overlay"></div>

  {{-- Cart drawer — legacy .drawer/.drawer.open + drawer-head/body/foot, cart-item,
      cart-thumb, cart-item-info (.cname/.cvar), qty-stepper, cprice, opt-label,
      voucher-row, pay-options/pay-option, sum-row (.total), checkout-btn --}}
  <aside class="flex h-full w-[400px] max-w-[92vw] translate-x-full flex-col bg-white shadow-[-14px_0_40px_rgba(58,37,41,0.25)] transition-transform duration-200 ease-out fixed top-0 right-0 z-[61] [&.open]:translate-x-0" id="cartDrawer">
    <div class="flex items-center justify-between border-b border-buyer-line px-[22px] py-5"><h3 class="m-0 font-serif text-[19px]">Your cart</h3><button class="js-cart-toggle border-0 bg-transparent text-[18px] text-neutral-500"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="flex flex-1 flex-col gap-4 overflow-y-auto px-[22px] py-[18px]">
      {{-- TODO: hardcoded cart contents, matching the original mockup.
          Replace with a real @foreach over cart items once Cart is built
          (see BUYER_STRUCTURE.md's open item on session vs. DB-backed cart). --}}
      <div class="flex gap-3">
        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-[10px] bg-linear-[135deg] from-secondary-300 to-primary-300 text-white"><i class="fa-solid fa-mug-hot"></i></div>
        <div class="flex-1">
          <div class="mb-[3px] text-[13px] font-semibold">Ceramic Mug Set (4pc)</div>
          <div class="mb-2 text-[11px] text-neutral-500">Color: Sage · Set of 4</div>
          <div class="inline-flex items-center rounded-full border border-buyer-line"><button class="h-[26px] w-[26px] border-0 bg-transparent text-[13px] text-buyer-ink-soft">-</button><span class="w-6 text-center text-[12.5px] font-semibold">1</span><button class="h-[26px] w-[26px] border-0 bg-transparent text-[13px] text-buyer-ink-soft">+</button></div>
        </div>
        <div class="text-[13px] font-bold whitespace-nowrap">Rp185.000</div>
      </div>
      <div class="flex gap-3">
        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-[10px] bg-linear-[135deg] from-secondary-300 to-primary-300 text-white"><i class="fa-solid fa-couch"></i></div>
        <div class="flex-1">
          <div class="mb-[3px] text-[13px] font-semibold">Linen Blend Throw Pillow</div>
          <div class="mb-2 text-[11px] text-neutral-500">Color: Blush · 45x45cm</div>
          <div class="inline-flex items-center rounded-full border border-buyer-line"><button class="h-[26px] w-[26px] border-0 bg-transparent text-[13px] text-buyer-ink-soft">-</button><span class="w-6 text-center text-[12.5px] font-semibold">2</span><button class="h-[26px] w-[26px] border-0 bg-transparent text-[13px] text-buyer-ink-soft">+</button></div>
        </div>
        <div class="text-[13px] font-bold whitespace-nowrap">Rp250.000</div>
      </div>

      <div>
        <div class="mb-2 text-[11.5px] font-bold tracking-[0.03em] text-buyer-ink-soft">VOUCHER</div>
        <div class="flex gap-2"><input class="min-w-0 flex-1 rounded-lg border border-buyer-line px-3 py-2.5 font-sans text-[12.5px]" type="text" placeholder="Enter voucher code"><button class="rounded-lg border-0 bg-buyer-ink px-4 text-xs font-semibold text-white">Apply</button></div>
      </div>

      <div>
        <div class="mb-2 text-[11.5px] font-bold tracking-[0.03em] text-buyer-ink-soft">PAYMENT METHOD</div>
        <div class="flex flex-col gap-2">
          <label class="flex items-center gap-2.5 rounded-lg border border-buyer-line px-3 py-2.5 text-[12.5px]"><input class="accent-buyer-primary-dark" type="radio" name="pay" checked> Bank transfer</label>
          <label class="flex items-center gap-2.5 rounded-lg border border-buyer-line px-3 py-2.5 text-[12.5px]"><input class="accent-buyer-primary-dark" type="radio" name="pay"> E-wallet</label>
          <label class="flex items-center gap-2.5 rounded-lg border border-buyer-line px-3 py-2.5 text-[12.5px]"><input class="accent-buyer-primary-dark" type="radio" name="pay"> Cash on delivery</label>
        </div>
      </div>
    </div>
    <div class="border-t border-buyer-line px-[22px] py-[18px]">
      <div class="mb-1.5 flex justify-between text-[12.5px] text-buyer-ink-soft"><span>Subtotal</span><span>Rp685.000</span></div>
      <div class="mb-1.5 flex justify-between text-[12.5px] text-buyer-ink-soft"><span>Voucher discount</span><span>−Rp50.000</span></div>
      <div class="mt-2 flex justify-between text-[15px] font-bold text-buyer-ink"><span>Total</span><span>Rp635.000</span></div>
      <a href="{{ route('buyer.checkout.index') }}" class="mt-3 block w-full rounded-full border-0 bg-buyer-ink p-[14px] text-center text-[13.5px] font-semibold text-white no-underline">Place order</a>
    </div>
  </aside>

  <div class="js-qv-overlay fixed inset-0 z-[70] hidden items-center justify-center bg-buyer-ink/40 p-5 [&.open]:flex" id="qvOverlay">
    {{-- TODO: static placeholder product, matching the original mockup.
        Once Products exist, populate this dynamically (data attributes on
        the card + JS, or a fetch) instead of hardcoding "Ceramic Mug Set". --}}
    <div class="flex max-h-[90vh] w-full max-w-[760px] overflow-hidden rounded-2xl bg-white shadow-[0_30px_60px_-20px_rgba(58,37,41,0.4)] max-[980px]:max-h-[85vh] max-[980px]:flex-col">
      <div class="flex flex-1 items-center justify-center bg-linear-[135deg] from-secondary-300 to-primary-300"><i class="fa-solid fa-mug-hot text-[64px] text-white opacity-90"></i></div>
      <div class="relative flex-1 overflow-y-auto p-[30px]">
        <button class="js-qv-close absolute top-4 right-4 border-0 bg-transparent text-[17px] text-neutral-500"><i class="fa-solid fa-xmark"></i></button>
        <h3 class="mt-0 mb-2 text-[22px]">Ceramic Mug Set (4pc)</h3>
        <div class="mb-[18px] text-xs text-neutral-500"><i class="fa-solid fa-star text-buyer-star"></i><i class="fa-solid fa-star text-buyer-star"></i><i class="fa-solid fa-star text-buyer-star"></i><i class="fa-solid fa-star text-buyer-star"></i><i class="fa-solid fa-star-half-stroke text-buyer-star"></i> 4.8 · 2,104 sold</div>
        <div class="mb-1 text-[19px] font-bold">Rp185.000<span class="ml-2 text-[12.5px] font-normal text-neutral-500 line-through">Rp250.000</span></div>

        <div class="mt-5">
          <div class="mb-2 text-[11.5px] font-bold tracking-[0.03em] text-buyer-ink-soft">COLOR</div>
          {{-- .swatch/.selected are JS toggle targets (buyer.js) — kept as hooks,
              selected state via [ &.selected]:. Colours stay inline styles:
              buyer.css also set them via var(--tertiary)/var(--primary)/
              var(--neutral) inline, so nothing is lost. --}}
          <div class="mb-[18px] flex gap-[9px]">
            <div class="swatch h-[30px] w-[30px] cursor-pointer rounded-full border-2 border-transparent [&.selected]:border-buyer-ink" style="background:var(--color-tertiary-300)"></div>
            <div class="swatch h-[30px] w-[30px] cursor-pointer rounded-full border-2 border-transparent [&.selected]:border-buyer-ink" style="background:var(--color-primary-300)"></div>
            <div class="swatch h-[30px] w-[30px] cursor-pointer rounded-full border-2 border-transparent [&.selected]:border-buyer-ink" style="background:var(--color-neutral-500)"></div>
          </div>
        </div>

        <div>
          <div class="mb-2 text-[11.5px] font-bold tracking-[0.03em] text-buyer-ink-soft">SET SIZE</div>
          <div class="mb-5 flex gap-[9px]">
            <button class="size-opt h-9 w-[38px] rounded-lg border border-buyer-line bg-white text-xs font-semibold [&.selected]:border-buyer-ink [&.selected]:bg-buyer-ink [&.selected]:text-white">2pc</button>
            <button class="size-opt h-9 w-[38px] rounded-lg border border-buyer-line bg-white text-xs font-semibold [&.selected]:border-buyer-ink [&.selected]:bg-buyer-ink [&.selected]:text-white selected">4pc</button>
            <button class="size-opt h-9 w-[38px] rounded-lg border border-buyer-line bg-white text-xs font-semibold [&.selected]:border-buyer-ink [&.selected]:bg-buyer-ink [&.selected]:text-white">6pc</button>
          </div>
        </div>

        <div class="mb-2 text-[11.5px] font-bold tracking-[0.03em] text-buyer-ink-soft">QUANTITY</div>
        <div class="mb-1.5 inline-flex items-center rounded-full border border-buyer-line"><button class="js-qv-qty-down h-[26px] w-[26px] border-0 bg-transparent text-[13px] text-buyer-ink-soft">-</button><span class="w-6 text-center text-[12.5px] font-semibold" id="qvQty">1</span><button class="js-qv-qty-up h-[26px] w-[26px] border-0 bg-transparent text-[13px] text-buyer-ink-soft">+</button></div>

      <div class="mt-2.5 flex items-center gap-3">
        <button class="js-qv-add flex-1 rounded-full border-0 bg-buyer-ink p-[13px] text-[13px] font-semibold text-white" data-product-id="1">Add to cart</button>
        <button class="flex h-[42px] w-[42px] items-center justify-center rounded-full border border-buyer-line bg-transparent text-[17px] text-buyer-ink-soft transition-colors duration-150 hover:bg-secondary-300 hover:text-buyer-ink"><i class="fa-regular fa-heart"></i></button>
      </div>
      {{-- View full details — href updated by JS when the modal opens via openQuickView(productId) --}}
      <a href="#" id="qv-pdp-link"
         class="mt-3 block text-center text-[12px] font-semibold text-primary-700 hover:underline">
        View full details →
      </a>
      </div>
    </div>
  </div>
  </div>

  <div class="fixed right-6 bottom-6 z-[65] hidden w-[320px] flex-col overflow-hidden rounded-2xl border border-buyer-line bg-white shadow-buyer [&.open]:flex" id="chatPanel">
    {{-- TODO: static conversation, matching the original mockup. Wire up to
        Chat/index and Chat/show once the chat backend exists. --}}
    <div class="flex items-center justify-between bg-buyer-ink px-4 py-[14px]"><span class="text-[13.5px] font-semibold text-white">Hearth & Nest · Store chat</span><button class="js-chat-toggle border-0 bg-transparent text-[15px] text-white"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="flex h-[230px] flex-col gap-2.5 overflow-y-auto bg-buyer-cream p-4">
      <div class="max-w-[78%] self-start rounded-xl rounded-bl-[3px] border border-buyer-line bg-white px-3 py-[9px] text-[12.5px] leading-[1.4]">Hi! Thanks for your order 🌿 it's been packed and handed to courier.</div>
      <div class="max-w-[78%] self-end rounded-xl rounded-br-[3px] bg-buyer-primary-dark px-3 py-[9px] text-[12.5px] leading-[1.4] text-white">Great, do you know when it'll arrive?</div>
      <div class="max-w-[78%] self-start rounded-xl rounded-bl-[3px] border border-buyer-line bg-white px-3 py-[9px] text-[12.5px] leading-[1.4]">Estimated 2 more days — I'll update you once it's out for delivery.</div>
    </div>
    <div class="flex border-t border-buyer-line"><input class="min-w-0 flex-1 border-0 px-[14px] py-3 font-sans text-[12.5px]" type="text" placeholder="Type a message..."><button class="border-0 bg-transparent px-[14px] text-[15px] text-buyer-primary-dark"><i class="fa-solid fa-paper-plane"></i></button></div>
  </div>

  {{-- Toast — legacy .toast/.toast.show. Hidden state = opacity-0 + translateY(20px)
      + pointer-events-none; .show flips all three via [&.show]:. --}}
  <div class="pointer-events-none fixed bottom-[26px] left-1/2 z-[80] flex translate-x-[-50%] translate-y-5 items-center gap-2 rounded-full bg-buyer-ink px-[22px] py-3 text-[12.5px] text-white opacity-0 transition-all duration-200 [&.show]:translate-y-0 [&.show]:opacity-100" id="toast"><i class="fa-solid fa-circle-check text-buyer-tertiary"></i> <span id="toastMessage">Added to cart</span></div>

  <script src="{{ asset('js/lucide.min.js') }}"></script>
  <script>lucide.createIcons();</script>
  </div>

  @vite(['resources/js/buyer/buyer.js'])
  @vite(['resources/js/buyer/buyer-register-modal.js'])
  @stack('scripts')
  </body>
  </html>
