{{-- resources/views/Components/navbar.blade.php
     Usage: @include('Components.navbar', [
         'cartCount' => 3,               // optional, defaults to 0
         'showLoginModal' => true,        // show login modal on click
         'hideGuestInLogin' => false,     // hide "Continue as Guest" in login modal
         'showRegisterModal' => false,    // show buyer register modal directly (vs link to register.type)
     ]) --}}
@php
    $cartCount = $cartCount ?? 0;
    $showLoginModal = $showLoginModal ?? true;
    $hideGuestInLogin = $hideGuestInLogin ?? false;
    $showRegisterModal = $showRegisterModal ?? false;

    // On auth pages the navbar Login button should link to /login instead of
    // opening the buyer modal (the page already has its own login form).
    $isAuthPage = request()->is('login') || request()->is('buyer/login') || request()->is('seller/login');
    if ($isAuthPage) {
        $showLoginModal = false;
    }

    // Style variant for the Login/Register links.
    //  - 'landing' (default): text links with a hairline divider (old _nav.scss look).
    //  - 'buyer': dark filled Login + outlined Register (replaces auth-buttons.css
    //    once it is deleted; identical values so the swap is seamless).
    // The legacy `auth-btn login-btn/register-btn` classes are kept ONLY while
    // auth-buttons.css is still loaded on buyer pages — remove at buyer wrap-up.
    $variant = $variant ?? 'landing';
    // Radius lives on each variant, NOT the base: auth-buttons.css sets
    // `border-radius: var(--radius-sm)` = 4px on `.html-body .auth-btn`
    // (0,2,0, unlayered) which BEATS Tailwind's `rounded-md` = 6px. So the
    // buyer variant must stay at 4px (`rounded-[4px]`) or deleting
    // auth-buttons.css at buyer wrap-up would silently nudge the buttons
    // from 4px to 6px. Landing loads no `auth-buttons.css`, so it keeps 6px.
    $authLinkBase = 'auth-btn inline-flex items-center whitespace-nowrap px-4 py-2 font-sans text-sm font-medium tracking-[0.7px] transition-colors duration-200';
    $authLoginClass = $variant === 'buyer'
        ? $authLinkBase.' login-btn rounded-[4px] bg-neutral-950 px-5 py-2 text-[13px] font-semibold uppercase tracking-[0.8px] text-cream hover:bg-primary-800 hover:text-cream max-sm:px-4 max-sm:text-xs'
        : $authLinkBase.' login-btn rounded-md border-r border-neutral-300 bg-transparent text-neutral-600 hover:bg-primary-100 hover:text-primary-800';
    $authRegisterClass = $variant === 'buyer'
        ? $authLinkBase.' register-btn rounded-[4px] border border-primary-800 bg-transparent px-5 py-2 text-[13px] font-semibold uppercase tracking-[0.8px] text-primary-800 hover:bg-primary-800 hover:text-cream max-sm:px-4 max-sm:text-xs'
        : $authLinkBase.' register-btn rounded-md bg-transparent text-primary-800 hover:bg-primary-100 hover:text-primary-800';
@endphp

<nav class="relative flex w-full flex-col items-start bg-cream" aria-label="Store navigation">
    <div class="mx-auto flex w-full max-w-[1440px] items-center justify-between gap-6 px-16 py-3.5 max-lg:flex-wrap max-lg:gap-y-3 max-lg:px-8 max-lg:py-4 max-sm:px-4 max-sm:py-3">
        {{-- Left group: brand --}}
        <a href="{{ url('/') }}" class="inline-flex shrink-0 items-center gap-2.5" aria-label="Zefanya home">
            <img class="h-10 w-10 rounded-full object-cover shadow-[0_1px_3px_rgba(41,38,38,0.10)] max-sm:h-8 max-sm:w-8" src="{{ asset('Images/Zefanya-Logo-128.png') }}" alt="Zefanya logo" />
            <span class="font-serif text-[22px] font-normal leading-none tracking-[0.2px] whitespace-nowrap text-neutral-950 max-sm:text-lg">Zefanya</span>
        </a>

        {{-- Center group: search container (dropdown | input | submit share one height) --}}
        <div class="flex h-[42px] min-w-0 shrink basis-[460px] grow-0 items-stretch overflow-hidden rounded border border-line bg-white max-lg:order-3 max-lg:w-full max-lg:max-w-full max-lg:basis-full">
            <button class="inline-flex w-[140px] shrink-0 cursor-pointer items-center justify-center gap-1.5 self-stretch bg-blush px-2.5 font-sans text-xs font-semibold tracking-[0.6px] whitespace-nowrap text-muted-ink max-sm:w-auto max-sm:px-2" type="button">
                <i data-lucide="chevron-down" width="14" height="14"></i>
                <span class="max-sm:hidden">All Categories</span>
            </button>
            <input class="min-w-0 shrink border-0 bg-transparent px-3 font-sans text-sm text-muted-ink placeholder:text-muted-ink/50 focus:outline-none" placeholder="Search for products..." type="text" />
            <button class="inline-flex w-[42px] shrink-0 cursor-pointer items-center justify-center self-stretch bg-neutral-950 text-cream max-sm:w-[38px]" type="button" aria-label="Search">
                <i data-lucide="search" width="16" height="16"></i>
            </button>
        </div>

        {{-- Right group: navigation & actions --}}
        <div class="flex shrink-0 items-center gap-5 max-sm:gap-3">
            <div class="h-6 w-px shrink-0 bg-neutral-300 max-sm:hidden"></div>

            <div class="inline-flex items-center gap-2 max-sm:gap-1">
                <a href="{{ Route::has('cart') ? route('cart') : '#' }}" class="relative inline-flex h-9 w-9 items-center justify-center rounded-md text-neutral-600 transition-colors duration-200 hover:bg-primary-100 hover:text-primary-800" aria-label="Cart">
                    <i data-lucide="shopping-cart" width="20" height="20"></i>
                    <span class="absolute top-0.5 right-0 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-primary-800 px-1 font-sans text-[10px] font-semibold leading-none text-cream">{{ $cartCount }}</span>
                </a>
                <a href="#" class="relative inline-flex h-9 w-9 items-center justify-center rounded-md text-neutral-600 transition-colors duration-200 hover:bg-primary-100 hover:text-primary-800" aria-label="Wishlist">
                    <i data-lucide="heart" width="20" height="20"></i>
                </a>
            </div>

            <div class="h-6 w-px shrink-0 bg-neutral-300 max-sm:hidden"></div>

            {{-- TODO(Buyer phase): the `auth-btn login-btn` / `auth-btn register-btn`
                 classes are the ONE spot where landing and buyer share a class
                 contract — buyer pages load auth-buttons.css against them
                 (dark Login / outlined Register buttons). Do not remove them
                 until the Buyer phase explicitly re-homes that styling.
                 See docs/css-migration-notes.md. --}}
            <div class="inline-flex items-center">
                @if ($showLoginModal)
                    {{-- Login button - opens modal, optionally hides "Continue as Guest" --}}
                    <a href="#"
                       data-login-trigger
                       @if ($hideGuestInLogin) data-hide-guest="true" @endif
                       class="{{ $authLoginClass }}">
                        Login
                    </a>
                @else
                    {{-- Login link - goes to login page --}}
                    <a href="{{ Route::has('login') ? route('login') : '#' }}" class="{{ $authLoginClass }}">
                        Login
                    </a>
                @endif

                @if ($showRegisterModal)
                    {{-- Register button - opens buyer register modal directly (for Buyer Page) --}}
                    <a href="#" data-buyer-register-trigger class="{{ $authRegisterClass }}">
                        Register
                    </a>
                @else
                    {{-- Register link - goes to register type selection page (for Landing Page) --}}
                    <a href="{{ Route::has('register.type') ? route('register.type') : '#' }}" class="{{ $authRegisterClass }}">
                        Register
                    </a>
                @endif
            </div>
        </div>
    </div>
</nav>