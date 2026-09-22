{{-- resources/views/Components/navbar.blade.php
     Usage: @include('Components.navbar', [
         'variant'          => 'buyer',   // 'landing' (default) or 'buyer'
         'showLoginModal'   => true,  // trigger login modal on click (guest)
         'hideGuestInLogin' => true,  // hide "Continue as Guest" inside modal
         'showRegisterModal'=> false, // open buyer register modal on Register click
     ])

     CART COUNT
     ─────────────────────────────────────────────────────────────────────────
     $cartCount is NOT passed by layouts — it is supplied by a view composer
     registered in AppServiceProvider::boot() ('Components.navbar'), which reads
     the authenticated buyer's real cart quantity. It falls back to 0 for
     guests, so no query runs for unauthenticated visitors.
     ────────────────────────────────────────────────────────────────────────

     GUEST AUTH RENDERING RULES
     ─────────────────────────────────────────────────────────────────────────
     landing variant  → always shows plain-text Login / Register (no buyer
                        auth check; buyer account widget never appears)
     buyer variant    → checks Auth::guard('buyer'):
                          signed-in  → avatar + account dropdown
                          guest      → same plain-text Login / Register

     The plain Login / Register links are identical on both variants so the
     navbar looks exactly the same for any unauthenticated visitor.
     ─────────────────────────────────────────────────────────────────────────
--}}
@php
    $cartCount         = $cartCount         ?? 0;
    $showLoginModal    = $showLoginModal    ?? true;
    $hideGuestInLogin  = $hideGuestInLogin  ?? false;
    $showRegisterModal = $showRegisterModal ?? false;
    $variant           = $variant           ?? 'landing';

    // Suppress the login modal trigger on dedicated auth pages.
    if (request()->is('login', 'buyer/login', 'seller/login')) {
        $showLoginModal = false;
    }

    // ── Buyer auth state (buyer variant only) ────────────────────────────────
    $buyerUser = null;
    if ($variant === 'buyer') {
        $buyerUser = Auth::guard('buyer')->check()
            ? Auth::guard('buyer')->user()
            : null;
    }

    $buyerFullName = '';
    $buyerInitial  = 'A';
    if ($buyerUser !== null) {
        $buyerFullName = method_exists($buyerUser, 'fullName')
            ? $buyerUser->fullName()
            : ($buyerUser->name ?? '');
        $buyerFullName = trim($buyerFullName) !== '' ? $buyerFullName : 'Account';
        $buyerInitial  = strtoupper(substr($buyerFullName, 0, 1));
    }
@endphp

@once
<style>
    /* Navbar search bar sizing — compact, balanced, responsive */
    .zf-search-wrap  { flex: 1 1 0%; width: 100%; max-width: 420px; }
    .zf-search-bar   { height: 44px; }
    .zf-search-cat   { width: 132px; font-size: 12.5px; }
    .zf-search-input { font-size: 14px; }
    .zf-search-btn   { width: 44px; }

    /* Tablet / small laptop: search drops to its own full-width row */
    @media (max-width: 1023px) {
        .zf-search-wrap { order: 3; flex: 0 0 100%; max-width: 100%; }
    }
    /* Phones: slightly shorter bar, icon-only category toggle */
    @media (max-width: 639px) {
        .zf-search-bar { height: 40px; }
        .zf-search-cat { width: auto; }
        .zf-search-btn { width: 40px; }
    }
</style>
@endonce

{{-- Blush bar, no white background --}}
<nav class="relative flex w-full flex-col items-start border-b border-line bg-blush" aria-label="Store navigation">
    <div class="mx-auto flex w-full max-w-[1440px] items-center justify-between gap-4 px-12 py-4 max-lg:flex-wrap max-lg:gap-y-3 max-lg:px-8 max-sm:px-4 max-sm:py-3">

        {{-- ── Brand ── --}}
        <a href="{{ url('/') }}" class="inline-flex shrink-0 items-center gap-2" aria-label="Zefanya home">
            <img class="h-10 w-10 rounded-full object-cover max-sm:h-8 max-sm:w-8"
                 src="{{ asset('Images/Zefanya-Logo.png') }}" alt="Zefanya logo" />
            <span class="font-serif text-[26px] font-normal leading-none whitespace-nowrap text-neutral-950 max-sm:text-xl">Zefanya</span>
        </a>

        {{-- ── Search bar + mega-category dropdown (compact, ~420px, centred between logo and icons) ── --}}
        <div class="zf-search-wrap relative">
            <input type="checkbox" id="catMenuToggle" class="peer hidden" />

            <div class="zf-search-bar flex min-w-0 items-stretch overflow-hidden rounded-lg border border-line bg-white">
                {{-- "All Categories" toggle: chevron first, then label --}}
                <label for="catMenuToggle"
                       class="zf-search-cat inline-flex shrink-0 cursor-pointer items-center justify-center gap-1.5 self-stretch border-r border-line bg-black/[0.04] px-2.5 font-sans font-semibold tracking-[0.3px] whitespace-nowrap text-muted-ink max-sm:px-2">
                    <i data-lucide="chevron-down" width="14" height="14"
                       class="shrink-0 transition-transform duration-150"></i>
                    <span class="max-sm:hidden">All Categories</span>
                </label>

                {{-- Search input --}}
                <input class="zf-search-input min-w-0 flex-1 border-0 bg-white px-3 font-sans text-muted-ink placeholder:text-muted-ink/50 focus:outline-none"
                       placeholder="Search for products…" type="text" aria-label="Search" />

                {{-- Search button --}}
                <button class="zf-search-btn inline-flex shrink-0 cursor-pointer items-center justify-center self-stretch bg-neutral-950 text-white transition-colors hover:bg-primary-900"
                        type="button" aria-label="Submit search">
                    <i data-lucide="search" width="18" height="18"></i>
                </button>
            </div>

            {{-- Mega-category panel (CSS-only, peer-checked) --}}
            <div class="absolute top-[calc(100%+6px)] left-0 z-50 hidden w-full min-w-[600px] gap-6 rounded-2xl border border-line bg-white p-5 shadow-xl peer-checked:flex max-lg:min-w-full">
                <div class="flex-1">
                    <div class="mb-3 flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-primary-800"></span>
                        <span class="text-[11px] font-bold uppercase tracking-[0.06em] text-neutral-700">Wardrobe &amp; Apparel</span>
                    </div>
                    <ul class="flex flex-col gap-2 text-[13px]">
                        <li><a href="#" class="font-medium text-neutral-900 hover:text-primary-800">Women's Premium Line</a></li>
                        <li><a href="#" class="font-medium text-neutral-900 hover:text-primary-800">Men's Classic Tailoring</a></li>
                        <li><a href="#" class="text-[12px] text-neutral-500 hover:text-primary-800">Minimalist Knitwear &amp; Outerwear</a></li>
                        <li><a href="#" class="text-[12px] text-neutral-500 hover:text-primary-800">Artisanal Footwear &amp; Loafers</a></li>
                    </ul>
                </div>
                <div class="flex-1">
                    <div class="mb-3 flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full bg-tertiary-300"></span>
                        <span class="text-[11px] font-bold uppercase tracking-[0.06em] text-neutral-700">Living &amp; Electronics</span>
                    </div>
                    <ul class="flex flex-col gap-2 text-[13px]">
                        <li><a href="#" class="font-medium text-neutral-900 hover:text-primary-800">Studio Audio &amp; Gadgets</a></li>
                        <li><a href="#" class="font-medium text-neutral-900 hover:text-primary-800">Ceramic &amp; Tableware</a></li>
                        <li><a href="#" class="text-[12px] text-neutral-500 hover:text-primary-800">Organic Bedding &amp; Linen</a></li>
                        <li><a href="#" class="text-[12px] text-neutral-500 hover:text-primary-800">Mechanical Timepieces</a></li>
                    </ul>
                </div>
                <div class="flex w-[200px] shrink-0 flex-col justify-between rounded-xl bg-secondary-100 p-4">
                    <div>
                        <span class="inline-block rounded-full bg-tertiary-200 px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.05em] text-tertiary-800">New Season</span>
                        <div class="mt-2 font-serif text-[15px] leading-snug text-neutral-950">Curated Autumn Capsule</div>
                        <p class="mt-1 text-[11px] leading-relaxed text-neutral-500">Handcrafted neutral staples, up to 40% off.</p>
                    </div>
                    <a href="#" class="mt-3 text-[12px] font-semibold text-neutral-800 hover:text-primary-800">Explore Capsule →</a>
                </div>
            </div>
        </div>

        {{-- ── Right group ── --}}
        <div class="flex shrink-0 items-center gap-5 max-sm:gap-2">

            {{-- Leading separator (left of cart) --}}
            <div class="h-5 w-px shrink-0 max-sm:hidden" style="background-color: rgba(0,0,0,.14);"></div>

            {{-- Cart + Wishlist icons --}}
            <div class="flex items-center gap-1">
                <a href="{{ Route::has('buyer.cart.index') ? route('buyer.cart.index') : '#' }}"
                   class="relative inline-flex h-10 w-10 items-center justify-center rounded text-neutral-500 transition-colors hover:text-primary-800"
                   aria-label="Cart">
                    <i data-lucide="shopping-cart" width="22" height="22"></i>
                    {{-- Badge is always shown, including "0" --}}
                    <span class="absolute top-0 right-0 inline-flex h-[16px] min-w-[16px] items-center justify-center rounded-full bg-primary-800 px-1 font-sans text-[10px] font-bold leading-none text-white" id="cartCount">{{ $cartCount }}</span>
                </a>
                <a href="#"
                   class="inline-flex h-10 w-10 items-center justify-center rounded text-neutral-500 transition-colors hover:text-primary-800"
                   aria-label="Wishlist">
                    <i data-lucide="heart" width="22" height="22"></i>
                </a>
            </div>

            {{-- Divider immediately after the heart icon, before Login / Register --}}
            <div class="h-5 w-px shrink-0 max-sm:hidden" style="background-color: rgba(0,0,0,.14);"></div>

            {{-- ── Auth area ── --}}
            @if ($buyerUser)
                {{-- Signed-in buyer: avatar + dropdown --}}
                <div class="relative">
                    <button type="button"
                            class="js-account-toggle inline-flex items-center gap-1.5 rounded px-1.5 py-1 font-sans text-[14px] font-medium text-neutral-700 transition-colors hover:bg-primary-50"
                            aria-haspopup="true" aria-expanded="false">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-200 text-[13px] font-semibold text-primary-900">
                            {{ $buyerInitial }}
                        </span>
                        <span class="hidden max-w-[110px] truncate sm:inline">{{ $buyerFullName }}</span>
                        <i data-lucide="chevron-down" width="13" height="13"></i>
                    </button>

                    <div id="accountDropdown"
                         class="absolute top-[calc(100%+6px)] right-0 z-50 hidden w-48 flex-col overflow-hidden rounded-xl border border-line bg-white py-1.5 shadow-lg [&.open]:flex">
                        <a href="{{ Route::has('buyer.account.index') ? route('buyer.account.index') : '#' }}"
                           class="px-4 py-2 font-sans text-[13px] text-neutral-700 hover:bg-primary-50 hover:text-primary-900">My Account</a>
                        <a href="{{ Route::has('buyer.orders.index') ? route('buyer.orders.index') : '#' }}"
                           class="px-4 py-2 font-sans text-[13px] text-neutral-700 hover:bg-primary-50 hover:text-primary-900">My Orders</a>
                        <div class="my-1 h-px bg-line"></div>
                        <form method="POST" action="{{ Route::has('unified.logout') ? route('unified.logout') : '#' }}">
                            @csrf
                            <button type="submit"
                                    class="w-full px-4 py-2 text-left font-sans text-[13px] text-neutral-700 hover:bg-primary-50 hover:text-primary-900">
                                Logout
                            </button>
                        </form>
                    </div>
                </div>

                {{-- Inline script — runs once, wires the toggle without needing buyer.js --}}
                <script>
                (function () {
                    var btn  = document.querySelector('.js-account-toggle');
                    var menu = document.getElementById('accountDropdown');
                    if (!btn || !menu || btn.dataset.init) return;
                    btn.dataset.init = '1';
                    btn.addEventListener('click', function () {
                        var open = menu.classList.toggle('open');
                        btn.setAttribute('aria-expanded', open);
                    });
                    document.addEventListener('click', function (e) {
                        if (!btn.contains(e.target) && !menu.contains(e.target)) {
                            menu.classList.remove('open');
                            btn.setAttribute('aria-expanded', 'false');
                        }
                    });
                })();
                </script>

            @else
                {{-- Guest: plain text Login | Register with a divider between — identical on landing and buyer pages --}}
                <div class="flex items-center gap-5">
                    @if ($showLoginModal)
                        <a href="#"
                           data-login-trigger
                           @if ($hideGuestInLogin) data-hide-guest="true" @endif
                           class="font-sans text-[14px] font-medium tracking-[0.3px] text-neutral-600 transition-colors hover:text-primary-800">
                            Login
                        </a>
                    @else
                        <a href="{{ Route::has('buyer.login') ? route('buyer.login') : (Route::has('login') ? route('login') : '#') }}"
                           class="font-sans text-[14px] font-medium tracking-[0.3px] text-neutral-600 transition-colors hover:text-primary-800">
                            Login
                        </a>
                    @endif

                    {{-- Divider between Login and Register --}}
                    <div class="h-5 w-px shrink-0" style="background-color: rgba(0,0,0,.14);"></div>

                    @if ($showRegisterModal)
                        <a href="#" data-buyer-register-trigger
                           class="font-sans text-[14px] font-medium tracking-[0.3px] text-neutral-600 transition-colors hover:text-primary-800">
                            Register
                        </a>
                    @else
                        <a href="{{ Route::has('register.type') ? route('register.type') : '#' }}"
                           class="font-sans text-[14px] font-medium tracking-[0.3px] text-neutral-600 transition-colors hover:text-primary-800">
                            Register
                        </a>
                    @endif
                </div>
            @endif

        </div>{{-- end right group --}}
    </div>
</nav>