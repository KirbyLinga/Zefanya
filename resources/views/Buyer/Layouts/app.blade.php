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

  {{-- Added-to-cart confirmation (stays on page; cart icon goes to /buyer/cart) --}}
  <div class="pointer-events-none fixed bottom-4 right-4 z-[80] w-80 max-w-[calc(100vw-2rem)] translate-y-10 opacity-0 transition-all duration-200 [&.show]:translate-y-0 [&.show]:opacity-100 [&.show]:pointer-events-auto" id="toast" role="status" aria-live="polite">
    <div class="rounded-lg border border-buyer-line bg-white p-4 shadow-lg">
      <div class="mb-3 flex items-start gap-2">
        <i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0 text-green-500"></i>
        <p class="text-sm font-semibold text-buyer-ink">Added to cart</p>
      </div>
      <div class="mb-3 border-b border-buyer-line pb-3">
        <p class="text-sm font-semibold text-buyer-ink" id="toastProductName">Product</p>
        <p class="mt-1 text-xs text-buyer-ink-soft" id="toastProductPrice">Price</p>
      </div>
      <button type="button" id="toastContinueBtn" class="w-full rounded border border-buyer-ink px-3 py-2 text-xs font-semibold text-buyer-ink transition-colors hover:bg-buyer-ink hover:text-white">
        Continue Shopping
      </button>
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

  @include('Components.buyer.wishlist-config')
@stack('scripts')
  </body>
  </html>
