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

    /* Tablet / mobile: search drops to its own full-width row */
    @media (max-width: 1023.98px) {
        .zf-search-wrap { order: 3; flex: 0 0 100%; max-width: 100%; }
        .zf-nav-main { display: none; }
    }
    /* Phones: slightly shorter bar, icon-only category toggle */
    @media (max-width: 639.98px) {
        .zf-search-bar { height: 40px; }
        .zf-search-cat { width: auto; }
        .zf-search-btn { width: 40px; }
        .zf-search-input { font-size: 16px; } /* Prevents iOS Safari input focus auto-zoom */
    }

    /* Rotate chevron when category accordion is open */
    #mobileCatAccordionToggle[aria-expanded="true"] #mobileCatChevron {
        transform: rotate(180deg);
    }
</style>
@endonce

{{-- Announcement bar (buyer variant only) --}}
@if ($variant === 'buyer' && $announcementText)
<div class="zf-navbar-sticky flex justify-center bg-buyer-ink px-4 py-2 text-center text-[12px] font-semibold text-white">
    {{ $announcementText }}
</div>
@endif

{{-- Blush navbar — sticky on buyer pages --}}
<nav class="zf-navbar-sticky relative flex w-full flex-col items-start border-b border-line bg-blush" aria-label="Store navigation">
    <div class="mx-auto flex w-full max-w-[1440px] items-center justify-between gap-4 px-12 py-4 max-lg:flex-wrap max-lg:gap-y-3 max-lg:px-8 max-sm:px-4 max-sm:py-3">

        {{-- ── Brand ── --}}
        {{-- The logo also provides a Home destination on small screens, where
             the main Home link is hidden. Exactly 1 aria-label="Go to Zefanya Home"
             is asserted by NavbarTest. --}}
        <a href="{{ route('buyer.home') }}"
           class="inline-flex shrink-0 items-center gap-2 no-underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800"
           aria-label="Go to Zefanya Home">
            <img class="h-10 w-10 rounded-full object-cover max-sm:h-8 max-sm:w-8"
                 src="{{ asset('Images/Zefanya-Logo.png') }}" alt="Zefanya logo" />
            <span class="font-serif text-[26px] font-normal leading-none whitespace-nowrap text-neutral-950 max-sm:text-xl">Zefanya</span>
        </a>

        {{-- ── Search bar + mega-category dropdown (compact, ~420px, centred between logo and icons) ── --}}
        <div class="zf-search-wrap relative">

            <div class="zf-search-bar flex min-w-0 items-stretch overflow-hidden rounded-lg border border-line bg-white">
                {{-- "All Categories" toggle: chevron first, then label --}}
                <button type="button" id="catMenuToggle" aria-label="All categories" aria-controls="categoryMenu" aria-expanded="false"
                       class="zf-search-cat inline-flex shrink-0 cursor-pointer items-center justify-center gap-1.5 self-stretch border-r border-line bg-black/[0.04] px-2.5 font-sans font-semibold tracking-[0.3px] whitespace-nowrap text-muted-ink max-sm:px-2">
                    <i data-lucide="chevron-down" width="14" height="14"
                       class="shrink-0 transition-transform duration-150"></i>
                    <span class="max-sm:hidden">All Categories</span>
                    <span class="sm:hidden text-xs">All</span>
                </button>

                {{-- Search input --}}
                <input class="zf-search-input min-w-0 flex-1 border-0 bg-white px-3 font-sans text-muted-ink placeholder:text-muted-ink/50 focus:outline-none"
                       placeholder="Search for products…" type="text" aria-label="Search" />

                <a href="{{ route('buyer.visual-search.index') }}" data-visual-search-open
                   class="inline-flex w-11 shrink-0 items-center justify-center border-l border-line bg-white text-primary-800 hover:bg-primary-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-800"
                   aria-label="Search by image" title="Search by image">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3"/></svg>
                </a>

                {{-- Search button --}}
                <button class="zf-search-btn inline-flex shrink-0 cursor-pointer items-center justify-center self-stretch bg-neutral-950 text-white transition-colors hover:bg-primary-900"
                        type="button" aria-label="Submit search">
                    <i data-lucide="search" width="18" height="18"></i>
                </button>
            </div>

            <noscript><a href="{{ route('buyer.categories.index') }}" class="inline-block px-2 py-2 text-sm text-primary-800 underline">Browse categories</a></noscript>

            {{-- Category navigation dropdown --}}
            <div id="categoryMenu" hidden class="absolute top-[calc(100%+6px)] left-0 z-50 w-72 rounded-2xl border border-line bg-white p-4 shadow-xl max-lg:w-full">
                {{-- Categories list: scrollable column with icons --}}
                <div class="flex flex-col gap-0.5 max-h-80 overflow-y-auto">
                    <a href="{{ route('buyer.categories.index') }}"
                       class="flex items-center justify-between px-4 py-2.5 rounded-lg text-xs font-semibold text-primary-800 hover:bg-primary-50 transition-colors mb-1">
                        <span>View All Categories</span>
                        <i data-lucide="arrow-right" width="14" height="14"></i>
                    </a>
                    @foreach ($categories as $category)
                        <a href="{{ route('buyer.categories.show', $category->id) }}" 
                           class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-[13px] font-medium text-neutral-700 hover:bg-primary-50 hover:text-primary-900 transition-colors group">
                            <i data-lucide="{{ $category->icon }}" width="18" height="18" class="shrink-0 text-neutral-500 group-hover:text-primary-800"></i>
                            <span class="flex-1">{{ $category->label }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ── Main nav links (desktop buyer variant only) ── --}}
        @if ($variant === 'buyer')
            <div class="zf-nav-main flex items-center gap-8">
                <a href="{{ route('buyer.home') }}" class="zf-nav-link text-buyer-ink {{ request()->routeIs('buyer.home') ? 'active' : '' }}" title="Home">Home</a>
                <a href="{{ route('buyer.products.index') }}" class="zf-nav-link text-buyer-ink {{ request()->routeIs('buyer.products.index') && ! request()->boolean('on_sale') ? 'active' : '' }}" title="Shop">Shop</a>
                @if ($hasRealSaleDiscounts ?? false)
                    <a href="{{ route('buyer.products.index', ['on_sale' => 1]) }}" class="zf-nav-link text-buyer-ink {{ request()->routeIs('buyer.products.index') && request()->boolean('on_sale') ? 'active' : '' }}" title="Sale">Sale</a>
                @endif
            </div>
        @endif

        {{-- ── Right group ── --}}
        <div class="flex shrink-0 items-center gap-3 max-sm:gap-1.5 lg:gap-5">

            {{-- Desktop leading separator (left of cart) --}}
            <div class="h-5 w-px shrink-0 hidden lg:block" style="background-color: rgba(0,0,0,.14);"></div>

            {{-- Cart + Wishlist + Messages + Notifications group --}}
            <div class="flex items-center gap-1">

                {{-- Notifications bell (buyerUser only; visible on mobile AND desktop) --}}
                @if ($buyerUser)
                    <div class="relative">
                        <button type="button" id="notificationToggle"
                                class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-neutral-500 transition-colors hover:bg-primary-50 hover:text-primary-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800"
                                aria-label="Notifications"
                                aria-haspopup="true" aria-expanded="false" aria-controls="notificationDropdown">
                            <i data-lucide="bell" width="22" height="22"></i>
                            <span data-notification-count class="absolute top-0.5 right-0.5 inline-flex h-[16px] min-w-[16px] items-center justify-center rounded-full bg-primary-800 px-1 font-sans text-[10px] font-bold leading-none text-white" @if ($unreadNotificationCount <= 0) style="display: none;" @endif>{{ $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount }}</span>
                        </button>

                        {{-- Notifications dropdown --}}
                        <div id="notificationDropdown"
                             class="absolute top-[calc(100%+8px)] right-0 z-50 hidden w-[calc(100vw-24px)] max-w-sm sm:w-80 max-h-96 flex-col overflow-hidden rounded-xl border border-line bg-white shadow-lg [&.open]:flex">
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

                {{-- Cart icon (visible on mobile AND desktop) --}}
                <a href="{{ route('buyer.cart.index') }}" id="cartDropdownToggle"
                   class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-neutral-500 transition-colors hover:bg-primary-50 hover:text-primary-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800"
                   aria-label="Cart">
                    <i data-lucide="shopping-cart" width="22" height="22"></i>
                    <span class="absolute top-0.5 right-0.5 inline-flex h-[16px] min-w-[16px] items-center justify-center rounded-full bg-primary-800 px-1 font-sans text-[10px] font-bold leading-none text-white" id="cartCount">{{ $cartCount }}</span>
                </a>

                {{-- Wishlist icon (desktop only; mobile accesses via menu) --}}
                @if ($buyerUser)
                    <a href="{{ route('buyer.wishlist.index') }}"
                       class="relative hidden lg:inline-flex h-10 w-10 items-center justify-center rounded-lg text-neutral-500 transition-colors hover:bg-primary-50 hover:text-primary-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800"
                       aria-label="Wishlist">
                        <i data-lucide="heart" width="22" height="22"></i>
                        <span class="absolute top-0.5 right-0.5 inline-flex h-[16px] min-w-[16px] items-center justify-center rounded-full bg-primary-800 px-1 font-sans text-[10px] font-bold leading-none text-white" id="wishlistCount" data-wishlist-count @if ($wishlistCount <= 0) style="display: none;" @endif>{{ $wishlistCount }}</span>
                    </a>
                @endif

                {{-- Messages icon (desktop only; mobile accesses via menu) --}}
                @if ($buyerUser)
                    <div class="relative hidden lg:block">
                        <a href="{{ route('buyer.messages.index') }}"
                           class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-neutral-500 transition-colors hover:bg-primary-50 hover:text-primary-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800"
                           aria-label="Messages"
                           data-messages-icon>
                            <i data-lucide="message-circle" width="22" height="22"></i>
                            <span class="absolute top-0.5 right-0.5 hidden h-[16px] min-w-[16px] items-center justify-center rounded-full bg-primary-800 px-1 font-sans text-[10px] font-bold leading-none text-white" id="messagesUnreadBadge" data-messages-unread-badge>0</span>
                        </a>
                    </div>
                @endif

                {{-- Mobile Menu Toggle button (lg:hidden) --}}
                <button type="button" id="mobileMenuToggle"
                        aria-label="Open navigation menu"
                        aria-controls="mobileNavMenu"
                        aria-expanded="false"
                        class="relative inline-flex lg:hidden h-10 w-10 items-center justify-center rounded-lg text-neutral-700 transition-colors hover:bg-primary-50 hover:text-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800">
                    <i data-lucide="menu" id="mobileMenuIconOpen" class="block" width="22" height="22"></i>
                    <i data-lucide="x" id="mobileMenuIconClose" class="hidden" width="22" height="22"></i>
                </button>

            </div>

            {{-- Desktop divider after cart icon, before Login / Register --}}
            <div class="h-5 w-px shrink-0 hidden lg:block" style="background-color: rgba(0,0,0,.14);"></div>

            {{-- ── Desktop Auth area (lg:flex) ── --}}
            @if ($buyerUser)
                {{-- Signed-in buyer: avatar + dropdown --}}
                <div class="relative hidden lg:block">
                    <button type="button"
                            class="js-account-toggle inline-flex items-center gap-1.5 rounded px-1.5 py-1 font-sans text-[14px] font-medium text-neutral-700 transition-colors hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800"
                            aria-haspopup="true" aria-expanded="false" aria-controls="accountDropdown">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary-200 text-[13px] font-semibold text-primary-900">
                            {{-- Profile photo when the buyer set one (Account Settings), else the initial. --}}
                            @if (method_exists($buyerUser, 'profilePhotoPath') && ($navPhoto = $buyerUser->profilePhotoPath()))
                                <img src="{{ route('buyer.account.photo.show', ['v' => md5($navPhoto)]) }}" alt="" class="size-full object-cover">
                            @else
                                {{ $buyerInitial }}
                            @endif
                        </span>
                        <span class="hidden max-w-[110px] truncate sm:inline lg:hidden xl:inline">{{ $buyerFullName }}</span>
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
                        <form method="POST" action="{{ route('unified.logout') }}" data-app-confirm data-confirm-variant="logout" data-confirm-title="Log out?" data-confirm-message="You will be signed out of all Zefanya accounts in this browser." data-confirm-label="Log out">
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
                {{-- Guest: plain text Login | Register (desktop) --}}
                <div class="hidden lg:flex items-center gap-5">
                    @if ($showLoginModal)
                        <a href="{{ route('home', ['login' => 1]) }}"
                           data-login-trigger
                           @if ($hideGuestInLogin) data-hide-guest="true" @endif
                           class="font-sans text-[14px] font-medium tracking-[0.3px] text-neutral-600 transition-colors hover:text-primary-800">
                            Login
                        </a>
                    @else
                        <a href="{{ route('home', ['login' => 1]) }}"
                           class="font-sans text-[14px] font-medium tracking-[0.3px] text-neutral-600 transition-colors hover:text-primary-800">
                            Login
                        </a>
                    @endif

                    {{-- Divider between Login and Register --}}
                    <div class="h-5 w-px shrink-0" style="background-color: rgba(0,0,0,.14);"></div>

                    @if ($showRegisterModal)
                        <a href="{{ route('register.buyer') }}" data-buyer-register-trigger
                           class="font-sans text-[14px] font-medium tracking-[0.3px] text-neutral-600 transition-colors hover:text-primary-800">
                            Register
                        </a>
                    @else
                        <a href="{{ route('register.type') }}"
                           class="font-sans text-[14px] font-medium tracking-[0.3px] text-neutral-600 transition-colors hover:text-primary-800">
                            Register
                        </a>
                    @endif
                </div>
            @endif

        </div>{{-- end right group --}}
    </div>

    {{-- ── Mobile Navigation Menu (Option B — Compact Expandable Menu) ── --}}
    <div id="mobileNavMenu"
         hidden
         class="w-full lg:hidden border-t border-line bg-white/95 backdrop-blur-md shadow-2xl max-h-[calc(100dvh-110px)] overflow-y-auto overscroll-contain"
         aria-label="Mobile store navigation">
        <div class="mx-auto max-w-lg flex flex-col gap-3 p-4">

            {{-- ── User Card / Guest Welcome ── --}}
            @if ($buyerUser)
                <div class="flex items-center gap-3 rounded-2xl bg-primary-50/70 border border-primary-100 p-3.5">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-primary-200 text-sm font-bold text-primary-900 border border-primary-300">
                        @if (method_exists($buyerUser, 'profilePhotoPath') && ($navPhoto = $buyerUser->profilePhotoPath()))
                            <img src="{{ route('buyer.account.photo.show', ['v' => md5($navPhoto)]) }}" alt="" class="size-full object-cover">
                        @else
                            {{ $buyerInitial }}
                        @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="font-sans text-sm font-bold text-buyer-ink truncate">{{ $buyerFullName }}</div>
                        <div class="font-sans text-xs text-buyer-ink-soft truncate">{{ $buyerUser->email ?? 'Buyer Account' }}</div>
                    </div>
                    <a href="{{ route('buyer.account.index') }}"
                       class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-white px-2.5 py-1.5 font-sans text-xs font-semibold text-primary-800 shadow-sm border border-line hover:bg-primary-50 transition-colors">
                        <span>Account</span>
                        <i data-lucide="chevron-right" width="13" height="13"></i>
                    </a>
                </div>
            @else
                <div class="rounded-2xl bg-primary-50/70 border border-primary-100 p-4">
                    <h3 class="font-serif text-base font-normal text-buyer-ink">Welcome to Zefanya</h3>
                    <p class="mt-1 font-sans text-xs text-buyer-ink-soft leading-relaxed">Sign in to track orders, save wishlist items, and manage your account.</p>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        @if ($showLoginModal)
                            <a href="{{ route('home', ['login' => 1]) }}"
                               data-login-trigger
                               @if ($hideGuestInLogin) data-hide-guest="true" @endif
                               class="inline-flex h-10 items-center justify-center rounded-xl bg-neutral-950 px-3 font-sans text-xs font-semibold uppercase tracking-wider text-white transition-colors hover:bg-primary-900">
                                Log In
                            </a>
                        @else
                            <a href="{{ route('home', ['login' => 1]) }}"
                               class="inline-flex h-10 items-center justify-center rounded-xl bg-neutral-950 px-3 font-sans text-xs font-semibold uppercase tracking-wider text-white transition-colors hover:bg-primary-900">
                                Log In
                            </a>
                        @endif

                        @if ($showRegisterModal)
                            <a href="{{ route('register.buyer') }}" data-buyer-register-trigger
                               class="inline-flex h-10 items-center justify-center rounded-xl border border-neutral-950 bg-white px-3 font-sans text-xs font-semibold uppercase tracking-wider text-neutral-950 transition-colors hover:bg-neutral-50">
                                Register
                            </a>
                        @else
                            <a href="{{ route('register.type') }}"
                               class="inline-flex h-10 items-center justify-center rounded-xl border border-neutral-950 bg-white px-3 font-sans text-xs font-semibold uppercase tracking-wider text-neutral-950 transition-colors hover:bg-neutral-50">
                                Register
                            </a>
                        @endif
                    </div>
                </div>
            @endif

            {{-- ── Main Store Links ── --}}
            <div class="flex flex-col gap-1 rounded-2xl border border-line bg-white p-2">
                <div class="px-3 pt-1 pb-1 font-sans text-[11px] font-bold uppercase tracking-wider text-buyer-ink-soft/70">
                    Store
                </div>
                <a href="{{ route('buyer.home') }}"
                   class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900 {{ request()->routeIs('buyer.home') ? 'bg-primary-50/80 font-semibold text-primary-900' : '' }}">
                    <i data-lucide="home" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                    <span class="flex-1">Home</span>
                </a>
                <a href="{{ route('buyer.products.index') }}"
                   class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900 {{ request()->routeIs('buyer.products.index') && !request()->boolean('on_sale') ? 'bg-primary-50/80 font-semibold text-primary-900' : '' }}">
                    <i data-lucide="shopping-bag" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                    <span class="flex-1">Shop All Products</span>
                </a>
                @if ($hasRealSaleDiscounts ?? false)
                    <a href="{{ route('buyer.products.index', ['on_sale' => 1]) }}"
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900 {{ request()->routeIs('buyer.products.index') && request()->boolean('on_sale') ? 'bg-primary-50/80 font-semibold text-primary-900' : '' }}">
                        <i data-lucide="tag" width="18" height="18" class="shrink-0 text-primary-800"></i>
                        <span class="flex-1">Sale & Offers</span>
                        <span class="rounded-full bg-primary-800 px-2 py-0.5 text-[10px] font-bold text-white uppercase">Sale</span>
                    </a>
                @endif

                {{-- Categories Accordion in Mobile Menu --}}
                <button type="button"
                        id="mobileCatAccordionToggle"
                        aria-expanded="false"
                        aria-controls="mobileCatAccordion"
                        class="flex w-full items-center justify-between rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900 text-left">
                    <span class="flex items-center gap-3">
                        <i data-lucide="layout-grid" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                        <span>Browse Categories</span>
                    </span>
                    <i data-lucide="chevron-down" id="mobileCatChevron" width="16" height="16" class="shrink-0 text-neutral-400 transition-transform duration-200"></i>
                </button>
                <div id="mobileCatAccordion" hidden class="flex flex-col gap-0.5 pl-8 pr-1 py-1">
                    <a href="{{ route('buyer.categories.index') }}"
                       class="flex items-center justify-between rounded-lg px-3 py-2 text-xs font-semibold text-primary-800 hover:bg-primary-50 transition-colors">
                        <span>All Categories</span>
                        <i data-lucide="arrow-right" width="13" height="13"></i>
                    </a>
                    @foreach ($categories as $category)
                        <a href="{{ route('buyer.categories.show', $category->id) }}"
                           class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-xs font-medium text-neutral-700 hover:bg-primary-50 hover:text-primary-900 transition-colors">
                            <i data-lucide="{{ $category->icon }}" width="15" height="15" class="shrink-0 text-neutral-500"></i>
                            <span class="truncate">{{ $category->label }}</span>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- ── Activity / Buyer Shopping Links ── --}}
            <div class="flex flex-col gap-1 rounded-2xl border border-line bg-white p-2">
                <div class="px-3 pt-1 pb-1 font-sans text-[11px] font-bold uppercase tracking-wider text-buyer-ink-soft/70">
                    Activity
                </div>
                @if ($buyerUser)
                    <a href="{{ route('buyer.orders.index') }}"
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900 {{ request()->routeIs('buyer.orders.*') ? 'bg-primary-50/80 font-semibold text-primary-900' : '' }}">
                        <i data-lucide="package" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                        <span class="flex-1">My Orders</span>
                    </a>
                    <a href="{{ route('buyer.wishlist.index') }}"
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900 {{ request()->routeIs('buyer.wishlist.*') ? 'bg-primary-50/80 font-semibold text-primary-900' : '' }}">
                        <i data-lucide="heart" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                        <span class="flex-1">Wishlist</span>
                        <span data-wishlist-count class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-800 px-1.5 font-sans text-[11px] font-bold text-white" @if ($wishlistCount <= 0) style="display: none;" @endif>{{ $wishlistCount }}</span>
                    </a>
                    <a href="{{ route('buyer.messages.index') }}"
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900 {{ request()->routeIs('buyer.messages.*') ? 'bg-primary-50/80 font-semibold text-primary-900' : '' }}">
                        <i data-lucide="message-circle" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                        <span class="flex-1">Messages</span>
                        <span data-messages-unread-badge class="hidden h-5 min-w-5 items-center justify-center rounded-full bg-primary-800 px-1.5 font-sans text-[11px] font-bold text-white">0</span>
                    </a>
                    <a href="{{ route('buyer.notifications.index') }}"
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900 {{ request()->routeIs('buyer.notifications.*') ? 'bg-primary-50/80 font-semibold text-primary-900' : '' }}">
                        <i data-lucide="bell" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                        <span class="flex-1">Notifications</span>
                        <span data-notification-count class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-800 px-1.5 font-sans text-[11px] font-bold text-white" @if ($unreadNotificationCount <= 0) style="display: none;" @endif>{{ $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount }}</span>
                    </a>
                @else
                    <a href="{{ route('buyer.cart.index') }}"
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900">
                        <i data-lucide="shopping-cart" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                        <span class="flex-1">Shopping Cart</span>
                        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-800 px-1.5 font-sans text-[11px] font-bold text-white">{{ $cartCount }}</span>
                    </a>
                    @if ($showLoginModal)
                        <a href="{{ route('home', ['login' => 1]) }}"
                           data-login-trigger
                           @if ($hideGuestInLogin) data-hide-guest="true" @endif
                           class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900">
                            <i data-lucide="package" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                            <span class="flex-1">My Orders</span>
                            <span class="font-sans text-xs text-buyer-ink-soft">Sign in</span>
                        </a>
                    @endif
                @endif
            </div>

            {{-- ── Preferences & Settings ── --}}
            <div class="flex flex-col gap-1 rounded-2xl border border-line bg-white p-2">
                <div class="px-3 pt-1 pb-1 font-sans text-[11px] font-bold uppercase tracking-wider text-buyer-ink-soft/70">
                    Preferences
                </div>
                @if ($buyerUser)
                    <a href="{{ route('buyer.account.address') }}"
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900">
                        <i data-lucide="map-pin" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                        <span class="flex-1">Saved Addresses</span>
                    </a>
                    <a href="{{ route('buyer.settings.index') }}"
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900">
                        <i data-lucide="settings" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                        <span class="flex-1">Settings</span>
                    </a>
                @endif
                <a href="{{ route('buyer.help.index') }}"
                   class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900">
                    <i data-lucide="help-circle" width="18" height="18" class="shrink-0 text-neutral-500"></i>
                    <span class="flex-1">Help & Support</span>
                </a>
                <button type="button"
                        data-theme-toggle
                        class="zf-theme-toggle flex w-full items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-buyer-ink transition-colors hover:bg-primary-50 hover:text-primary-900 border-0 bg-transparent text-left cursor-pointer"
                        aria-label="Switch to light mode"
                        aria-pressed="false">
                    <i data-lucide="moon" class="zf-icon-moon block" width="18" height="18" aria-hidden="true"></i>
                    <i data-lucide="sun" class="zf-icon-sun block" width="18" height="18" aria-hidden="true"></i>
                    <span class="theme-label flex-1">Dark mode</span>
                </button>
                @if ($buyerUser)
                    <div class="my-1 h-px bg-line"></div>
                    <form method="POST" action="{{ route('unified.logout') }}" data-app-confirm data-confirm-variant="logout" data-confirm-title="Log out?" data-confirm-message="You will be signed out of all Zefanya accounts in this browser." data-confirm-label="Log out">
                        @csrf
                        <button type="submit"
                                class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 font-sans text-sm font-medium text-red-600 transition-colors hover:bg-red-50 text-left cursor-pointer">
                            <i data-lucide="log-out" width="18" height="18" class="shrink-0 text-red-500"></i>
                            <span class="flex-1">Log out</span>
                        </button>
                    </form>
                @endif
            </div>

        </div>
    </div>
</nav>

{{-- Inline navigation script for fast standalone execution --}}
<script>
(function () {
    // ── Category mega-menu dropdown ──
    const catTrigger = document.getElementById('catMenuToggle');
    const catMenu = document.getElementById('categoryMenu');
    if (catTrigger && catMenu) {
        function closeCat(restoreFocus) {
            catMenu.hidden = true;
            catTrigger.setAttribute('aria-expanded', 'false');
            if (restoreFocus) catTrigger.focus();
        }

        catTrigger.addEventListener('click', function () {
            const open = catTrigger.getAttribute('aria-expanded') !== 'true';
            catTrigger.setAttribute('aria-expanded', String(open));
            catMenu.hidden = !open;
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !catMenu.hidden) {
                event.preventDefault();
                closeCat(true);
            }
        });

        document.addEventListener('click', function (event) {
            if (!catMenu.hidden && !catMenu.contains(event.target) && !catTrigger.contains(event.target)) {
                closeCat(false);
            }
        });
    }

    // ── Mobile Navigation Menu Toggle (Option B) ──
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const mobileMenu = document.getElementById('mobileNavMenu');
    const iconOpen = document.getElementById('mobileMenuIconOpen');
    const iconClose = document.getElementById('mobileMenuIconClose');

    if (mobileToggle && mobileMenu) {
        function setMobileMenu(open, restoreFocus) {
            mobileMenu.hidden = !open;
            mobileToggle.setAttribute('aria-expanded', String(open));
            mobileToggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
            if (iconOpen && iconClose) {
                iconOpen.classList.toggle('hidden', open);
                iconOpen.classList.toggle('block', !open);
                iconClose.classList.toggle('hidden', !open);
                iconClose.classList.toggle('block', open);
            }
            if (open) {
                // Close other dropdowns
                if (catMenu && !catMenu.hidden) {
                    catMenu.hidden = true;
                    if (catTrigger) catTrigger.setAttribute('aria-expanded', 'false');
                }
                const notifDropdown = document.getElementById('notificationDropdown');
                if (notifDropdown && notifDropdown.classList.contains('open')) {
                    notifDropdown.classList.remove('open');
                    const notifToggle = document.getElementById('notificationToggle');
                    if (notifToggle) notifToggle.setAttribute('aria-expanded', 'false');
                }
                if (window.lucide && typeof window.lucide.createIcons === 'function') {
                    window.lucide.createIcons();
                }
            } else if (restoreFocus && mobileToggle.isConnected) {
                mobileToggle.focus();
            }
        }

        mobileToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = mobileToggle.getAttribute('aria-expanded') === 'true';
            setMobileMenu(!isOpen);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !mobileMenu.hidden) {
                e.preventDefault();
                setMobileMenu(false, true);
            }
        });

        document.addEventListener('click', function (e) {
            if (!mobileMenu.hidden && !mobileMenu.contains(e.target) && !mobileToggle.contains(e.target)) {
                setMobileMenu(false, false);
            }
        });

        // Close when switching to desktop viewport
        const desktopMq = window.matchMedia('(min-width: 1024px)');
        desktopMq.addEventListener('change', function (e) {
            if (e.matches && !mobileMenu.hidden) {
                setMobileMenu(false, false);
            }
        });
    }

    // ── Mobile Menu Category Accordion ──
    const catAccToggle = document.getElementById('mobileCatAccordionToggle');
    const catAccordion = document.getElementById('mobileCatAccordion');
    if (catAccToggle && catAccordion) {
        catAccToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            const open = catAccToggle.getAttribute('aria-expanded') !== 'true';
            catAccToggle.setAttribute('aria-expanded', String(open));
            catAccordion.hidden = !open;
            if (open && window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        });
    }
})();
</script>

@once
    @unless(request()->routeIs('buyer.visual-search.*'))
        <dialog id="visual-search-dialog" aria-labelledby="visual-search-dialog-title" class="m-auto max-h-[90dvh] w-[92vw] max-w-5xl overflow-y-auto rounded-2xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-4 text-[var(--text-ink)] shadow-xl backdrop:bg-black/50 sm:p-6">
            <div class="mb-4 flex items-start justify-between gap-3">
                <h2 id="visual-search-dialog-title" class="font-serif text-2xl text-[var(--text-ink)]">Search by image</h2>
                <button type="button" data-visual-search-close aria-label="Close image search" class="inline-flex size-11 shrink-0 items-center justify-center rounded-lg border border-[var(--border-strong)] text-xl text-[var(--text-ink)] hover:bg-[var(--bg-raised)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]"><span aria-hidden="true">&times;</span></button>
            </div>
            <x-buyer.visual-search-panel id="navbar-visual-search" />
        </dialog>
    @endunless
    @push('scripts')
        @vite('resources/js/buyer/visual-search.js')
    @endpush
@endonce
