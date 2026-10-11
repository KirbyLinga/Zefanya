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
     landing variant  → PUBLIC ONLY. Never reads the buyer guard:
                          Login / Register (+ cart icon).
                          Route::get('/') redirects a signed-in buyer to
                          buyer.home before this navbar is ever rendered, so
                          'landing' is never shown to an authenticated buyer.
     buyer variant    → reads Auth::guard('buyer'):
                          signed-in buyer → avatar + account dropdown
                          guest           → Login / Register
                           (cart icon shown in both cases)
     ─────────────────────────────────────────────────────────────────────────
--}}
@php
    $cartCount         = $cartCount         ?? 0;
    $wishlistCount     = $wishlistCount     ?? 0;
    $unreadNotificationCount = $unreadNotificationCount ?? 0;
    $showLoginModal    = $showLoginModal    ?? true;
    $hideGuestInLogin  = $hideGuestInLogin  ?? false;
    $showRegisterModal = $showRegisterModal ?? false;
    $variant           = $variant           ?? 'landing';

    // Suppress the login modal trigger on dedicated auth pages.
    if (request()->is('login', 'buyer/login', 'seller/login')) {
        $showLoginModal = false;
    }

    // ── Buyer auth state (buyer variant ONLY) ────────────────────────────────
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

    $announcementText = config('buyer.announcements.top_bar');
@endphp

@once
<style>
    /* Navbar search bar sizing — compact, balanced, responsive */
    .zf-search-wrap  { flex: 1 1 0%; width: 100%; max-width: 420px; }
    .zf-search-bar   { height: 44px; }
    .zf-search-cat   { width: 132px; font-size: 12.5px; }
    .zf-search-input { font-size: 14px; }
    .zf-search-btn   { width: 44px; }

    /* Sticky navbar */
    .zf-navbar-sticky {
        position: sticky;
        top: 0;
        z-index: 40;
    }

    /* Main nav links */
    .zf-nav-link {
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        position: relative;
        padding-bottom: 4px;
    }

    .zf-nav-link::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 2px;
        background-color: var(--link, #c07a85);
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 200ms ease;
    }

    .zf-nav-link:hover::after,
    .zf-nav-link.active::after {
        transform: scaleX(1);
    }

    /* Tablet / small laptop: search drops to its own full-width row */
    @media (max-width: 1023px) {
        .zf-search-wrap { order: 3; flex: 0 0 100%; max-width: 100%; }
        .zf-nav-main { display: none; }
    }
    /* Phones: slightly shorter bar, icon-only category toggle */
    @media (max-width: 639px) {
        .zf-search-bar { height: 40px; }
        .zf-search-cat { width: auto; }
        .zf-search-btn { width: 40px; }
    }
</style>
@endonce

{{-- Announcement bar (buyer variant only) --}}
@if ($variant === 'buyer' && $announcementText)
<div class="zf-navbar-sticky flex justify-center bg-buyer-ink px-4 py-2 text-center text-[12px] font-semibold text-white">
    {{ $announcementText }}
</div>
@endif

{{-- Blush navbar —sticky on buyer pages --}}
<nav class="zf-navbar-sticky relative flex w-full flex-col items-start border-b border-line bg-blush" aria-label="Store navigation">
    <div class="mx-auto flex w-full max-w-[1440px] items-center justify-between gap-4 px-12 py-4 max-lg:flex-wrap max-lg:gap-y-3 max-lg:px-8 max-sm:px-4 max-sm:py-3">

        {{-- ── Brand ── --}}
        {{-- Non-clickable in every navbar state (public, guest, buyer): the logo
             and wordmark are branding only — no href, no tab stop, default
             cursor. Do not turn this back into an <a>. --}}
        <span class="inline-flex shrink-0 items-center gap-2" aria-label="Zefanya home">
            <img class="h-10 w-10 rounded-full object-cover max-sm:h-8 max-sm:w-8"
                 src="{{ asset('Images/Zefanya-Logo.png') }}" alt="Zefanya logo" />
            <span class="font-serif text-[26px] font-normal leading-none whitespace-nowrap text-neutral-950 max-sm:text-xl">Zefanya</span>
        </span>

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
            <div class="absolute top-[calc(100%+6px)] left-0 z-50 hidden w-full min-w-[600px] gap-6 rounded-2xl border border-line bg-white p-5 shadow-xl peer-checked:flex max-lg:min-w-full max-lg:flex-col">
                {{-- Categories grid: split into columns, max 2-3 per column --}}
                @foreach ($categories->chunk(ceil($categories->count() / 2)) as $column)
                    <div class="flex-1">
                        @foreach ($column as $index => $category)
                            @if ($index === 0)
                                <div class="mb-3 flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-primary-800"></span>
                                    <a href="{{ route('buyer.categories.show', $category->id) }}" class="text-[11px] font-bold uppercase tracking-[0.06em] text-neutral-700 hover:text-primary-800">
                                        {{ $category->label }}
                                    </a>
                                </div>
                            @else
                                <div class="mb-3 flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full" style="background-color: var(--primary-color, #c07a85); opacity: 0.6;"></span>
                                    <a href="{{ route('buyer.categories.show', $category->id) }}" class="text-[11px] font-bold uppercase tracking-[0.06em] text-neutral-700 hover:text-primary-800">
                                        {{ $category->label }}
                                    </a>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Main nav links (buyer variant only) ── --}}
        @if ($variant === 'buyer')
            <div class="zf-nav-main flex items-center gap-8">
                <a href="{{ route('buyer.home') }}" class="zf-nav-link text-buyer-ink {{ request()->routeIs('buyer.home') ? 'active' : '' }}" title="Home">Home</a>
                <a href="{{ route('buyer.products.index') }}" class="zf-nav-link text-buyer-ink {{ request()->routeIs('buyer.products.index') ? 'active' : '' }}" title="Shop">Shop</a>
                <a href="#" class="zf-nav-link text-buyer-ink" title="Sale">Sale</a>
            </div>
        @endif

        {{-- ── Right group ── --}}
        <div class="flex shrink-0 items-center gap-5 max-sm:gap-2">

            {{-- Leading separator (left of cart) --}}
            <div class="h-5 w-px shrink-0 max-sm:hidden" style="background-color: rgba(0,0,0,.14);"></div>

            {{-- Cart + Wishlist + Notifications icons --}}
            <div class="flex items-center gap-1">
                <button type="button" id="cartDropdownToggle"
                   class="relative inline-flex h-10 w-10 items-center justify-center rounded text-neutral-500 transition-colors hover:text-primary-800"
                   aria-label="Cart"
                   aria-haspopup="true" aria-expanded="false">
                    <i data-lucide="shopping-cart" width="22" height="22"></i>
                    <span class="absolute top-0 right-0 inline-flex h-[16px] min-w-[16px] items-center justify-center rounded-full bg-primary-800 px-1 font-sans text-[10px] font-bold leading-none text-white" id="cartCount">{{ $cartCount }}</span>
                </button>

                {{-- Mini cart dropdown --}}
                @include('Components.buyer.mini-cart')

                {{-- Wishlist icon --}}
                @if ($buyerUser)
                    <button type="button" data-wishlist-toggle
                            class="relative inline-flex h-10 w-10 items-center justify-center rounded text-neutral-500 transition-colors hover:text-primary-800"
                            aria-label="Wishlist"
                            data-product-id="">
                        <i data-lucide="heart" width="22" height="22"></i>
                        <span class="absolute top-0 right-0 inline-flex h-[16px] min-w-[16px] items-center justify-center rounded-full bg-primary-800 px-1 font-sans text-[10px] font-bold leading-none text-white" id="wishlistCount">{{ $wishlistCount }}</span>
                    </button>
                @endif

                {{-- Messages icon --}}
                @if ($buyerUser)
                    <div class="relative">
                        <a href="{{ route('buyer.messages.index') }}"
                           class="relative inline-flex h-10 w-10 items-center justify-center rounded text-neutral-500 transition-colors hover:text-primary-800"
                           aria-label="Messages"
                           data-messages-icon>
                            <i data-lucide="message-circle" width="22" height="22"></i>
                            <span class="absolute top-0 right-0 hidden h-[16px] min-w-[16px] items-center justify-center rounded-full bg-primary-800 px-1 font-sans text-[10px] font-bold leading-none text-white" id="messagesUnreadBadge">0</span>
                        </a>
                    </div>
                @endif

                {{-- Notifications bell --}}
                @if ($buyerUser)
                    <div class="relative">
                        <button type="button" id="notificationToggle"
                                class="relative inline-flex h-10 w-10 items-center justify-center rounded text-neutral-500 transition-colors hover:text-primary-800"
                                aria-label="Notifications"
                                aria-haspopup="true" aria-expanded="false">
                            <i data-lucide="bell" width="22" height="22"></i>
                            @if ($unreadNotificationCount > 0)
                                <span class="absolute top-0 right-0 inline-flex h-[16px] min-w-[16px] items-center justify-center rounded-full bg-primary-800 px-1 font-sans text-[10px] font-bold leading-none text-white">{{ min($unreadNotificationCount, 9) }}</span>
                            @endif
                        </button>

                        {{-- Notifications dropdown --}}
                        <div id="notificationDropdown"
                             class="absolute top-[calc(100%+8px)] right-0 z-50 hidden w-80 max-h-96 flex-col overflow-hidden rounded-xl border border-line bg-white shadow-lg [&.open]:flex">
                            <div class="flex items-center justify-between border-b border-line px-4 py-3">
                                <h3 class="font-sans font-semibold text-buyer-ink">Notifications</h3>
                                <button type="button" id="markAllRead" class="text-[12px] font-semibold text-primary-800 hover:text-primary-900">Mark all read</button>
                            </div>
                            <div id="notificationList" class="flex-1 overflow-y-auto">
                                {{-- Will be populated by AJAX --}}
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Light/dark toggle removed - moved to profile dropdown --}}

            </div>

            {{-- Divider after cart icon, before Login / Register --}}
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
                         class="absolute top-[calc(100%+6px)] right-0 z-50 hidden w-56 flex-col overflow-hidden rounded-xl border border-line bg-white py-1.5 shadow-lg [&.open]:flex">
                        <a href="{{ route('buyer.account.index') }}"
                           class="flex items-center gap-2 px-4 py-2 font-sans text-[13px] text-neutral-700 hover:bg-primary-50 hover:text-primary-900">
                            <i data-lucide="user" width="16" height="16"></i>
                            My Account
                        </a>
                        <a href="{{ route('buyer.orders.index') }}"
                           class="flex items-center gap-2 px-4 py-2 font-sans text-[13px] text-neutral-700 hover:bg-primary-50 hover:text-primary-900">
                            <i data-lucide="package" width="16" height="16"></i>
                            My Orders
                        </a>
                        <a href="{{ route('buyer.wishlist.index') }}"
                           class="flex items-center gap-2 px-4 py-2 font-sans text-[13px] text-neutral-700 hover:bg-primary-50 hover:text-primary-900">
                            <i data-lucide="heart" width="16" height="16"></i>
                            Wishlist
                        </a>
                        <a href="{{ route('buyer.account.address') }}"
                           class="flex items-center gap-2 px-4 py-2 font-sans text-[13px] text-neutral-700 hover:bg-primary-50 hover:text-primary-900">
                            <i data-lucide="map-pin" width="16" height="16"></i>
                            Saved Addresses
                        </a>
                        <a href="{{ route('buyer.settings.index') }}"
                           class="flex items-center gap-2 px-4 py-2 font-sans text-[13px] text-neutral-700 hover:bg-primary-50 hover:text-primary-900">
                            <i data-lucide="settings" width="16" height="16"></i>
                            Settings
                        </a>
                        <a href="{{ route('buyer.help.index') }}"
                           class="flex items-center gap-2 px-4 py-2 font-sans text-[13px] text-neutral-700 hover:bg-primary-50 hover:text-primary-900">
                            <i data-lucide="help-circle" width="16" height="16"></i>
                            Help & Support
                        </a>
                        <div class="my-1 h-px bg-line"></div>
                        <button type="button"
                                data-theme-toggle
                                class="zf-theme-toggle w-full flex items-center gap-2 px-4 py-2 text-left font-sans text-[13px] text-neutral-700 hover:bg-primary-50 hover:text-primary-900 border-0 bg-transparent"
                                aria-label="Switch to light mode"
                                aria-pressed="false">
                            <i data-lucide="moon" class="zf-icon-moon block" width="16" height="16" aria-hidden="true"></i>
                            <i data-lucide="sun" class="zf-icon-sun block" width="16" height="16" aria-hidden="true"></i>
                            <span class="theme-label">Light mode</span>
                        </button>
                        <div class="my-1 h-px bg-line"></div>
                        <form method="POST" action="{{ route('unified.logout') }}">
                            @csrf
                            <button type="submit"
                                    class="w-full flex items-center gap-2 px-4 py-2 text-left font-sans text-[13px] text-neutral-700 hover:bg-primary-50 hover:text-primary-900">
                                <i data-lucide="log-out" width="16" height="16"></i>
                                Logout
                            </button>
                        </form>
                    </div>
                </div>

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