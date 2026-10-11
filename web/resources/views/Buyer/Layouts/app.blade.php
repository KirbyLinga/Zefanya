  <!DOCTYPE html>
  <html lang="en" data-theme-key="buyer.theme">
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
      lost. No buyer markup uses its component classes (.btn/.input/.
      .search-bar/.progress/.nav-pill/.action-btn).
      Same state the landing layout is already in. See
      docs/css-migration-notes.md → "CROSS-CUTTING HAZARD". --}}
  @vite(['resources/css/app.css', 'resources/css/login-modal.css', 'resources/css/buyer/buyer-register-modal.css'])
  @stack('styles')
  <script>
    {{-- Theme init MUST run before the stylesheet paints: reads the buyer
         area's own theme — localStorage 'buyer.theme', the key declared once
         on the <html> tag above — → data-theme on <html>. The seller,
         logistics and public shells each have their own key; 'zf-theme' is
         only the pre-split shared key, read as a one-time fallback. --}}
    (function () {
      try {
        var key = document.documentElement.dataset.themeKey || 'zf-theme';
        var theme = localStorage.getItem(key) || localStorage.getItem('zf-theme');
        document.documentElement.dataset.theme = (theme === 'dark') ? 'dark' : 'light';
      } catch (e) {
        document.documentElement.dataset.theme = 'light';
      }
    })();

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
  <body class="m-0 p-0 flex flex-col min-h-screen">

  <div class="html-body flex flex-col min-h-screen">

  @include('Components.navbar', [
      'variant' => 'buyer',         // dark filled Login / outlined Register (replaces auth-buttons.css)
      'showLoginModal' => true,
      'hideGuestInLogin' => true,   // Hide "Continue as Guest" on buyer pages
      'showRegisterModal' => true,  // Open buyer register modal directly
  ])

  <main class="flex-1">
    @yield('content')
  </main>

  @include('Components.login-modal')

  @include('Components.buyer-register-modal')

  @include('Buyer.Layouts.footer')

  </div>

  {{-- Global overlay widgets — present on every buyer page.
      TOGGLE-CLASS CONTRACT (buyer.js owns ALL of these):
        `open` → cart drawer + overlay (toggleCart)
        `show` → toast (showToast, 1800ms timeout) --}}

  {{-- Overlay (behind cart drawer) --}}
  <div class="js-overlay pointer-events-none fixed inset-0 z-[60] bg-buyer-ink/35 opacity-0 transition-opacity duration-200 [&.open]:pointer-events-auto [&.open]:opacity-100" id="overlay"></div>

  {{-- Cart drawer --}}
  <aside class="flex h-full w-[400px] max-w-[92vw] translate-x-full flex-col bg-white shadow-[-14px_0_40px_rgba(58,37,41,0.25)] transition-transform duration-200 ease-out fixed top-0 right-0 z-[61] [&.open]:translate-x-0" id="cartDrawer">
    <div class="flex items-center justify-between border-b border-buyer-line px-[22px] py-5"><h3 class="m-0 font-serif text-[19px]">Your cart</h3><button class="js-cart-toggle border-0 bg-transparent text-[18px] text-neutral-500"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="flex flex-1 flex-col gap-4 overflow-y-auto px-[22px] py-[18px]">
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

  {{-- Toast / Confirmation Card --}}
  <div class="pointer-events-none fixed bottom-4 right-4 z-[80] w-80 max-w-[calc(100vw-2rem)] translate-y-10 opacity-0 transition-all duration-200 [&.show]:translate-y-0 [&.show]:opacity-100 [&.show]:pointer-events-auto" id="toast">
    <div class="bg-white rounded-lg shadow-lg border border-buyer-line p-4">
      <div class="flex items-start gap-2 mb-3">
        <i class="fa-solid fa-circle-check text-green-500 flex-shrink-0 mt-0.5"></i>
        <div class="flex-1 min-w-0">
          <p class="font-semibold text-buyer-ink text-sm">Added to cart</p>
        </div>
      </div>
      <div class="mb-3 pb-3 border-b border-buyer-line">
        <p class="font-semibold text-buyer-ink text-sm" id="toastProductName">Product</p>
        <p class="text-buyer-ink-soft text-xs mt-1" id="toastProductPrice">Price</p>
      </div>
      <div class="flex gap-2">
        <button type="button" id="toastContinueBtn" class="flex-1 px-3 py-2 border border-buyer-ink text-buyer-ink text-xs font-semibold rounded hover:bg-buyer-ink hover:text-white transition-colors">
          Continue Shopping
        </button>
        <a href="{{ route('buyer.cart.index') }}" id="toastViewCartBtn" class="flex-1 px-3 py-2 bg-buyer-ink text-white text-xs font-semibold rounded hover:bg-opacity-90 transition-colors text-center no-underline">
          View Cart
        </a>
      </div>
    </div>
  </div>

  <script src="{{ asset('js/lucide.min.js') }}"></script>
  <script>lucide.createIcons();</script>

  @vite(['resources/js/buyer/buyer.js', 'resources/js/buyer/navbar.js', 'resources/js/buyer/search.js', 'resources/js/buyer/wishlist-cards.js', 'resources/js/buyer/add-to-cart-animation.js', 'resources/js/buyer/messages.js'])

  {{-- Shared PSGC address module for the buyer register modal included above
       (line 67). It must load before buyer-register-modal.js, which reads
       window.zfAddressLookup at init. --}}
  @vite(['resources/js/buyer/address-lookup.js'])
  @vite(['resources/js/buyer/buyer-register-modal.js'])

  {{-- Light/dark toggle behaviour --}}
  @vite('resources/js/seller/theme-toggle.js')

  @stack('scripts')
  </body>
  </html>
