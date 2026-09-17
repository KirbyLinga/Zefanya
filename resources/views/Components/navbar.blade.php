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
@endphp

<div class="topnavbar-shared">
    <div class="navbar">
        {{-- Left group: brand --}}
        <a href="{{ url('/') }}" class="brand" aria-label="Zefanya home">
            <img class="brand__logo" src="{{ asset('Images/Zefanya-Logo-128.png') }}" alt="Zefanya logo" />
            <span class="brand__name">Zefanya</span>
        </a>

        {{-- Center group: search container (dropdown | input | submit share one height) --}}
        <div class="search-container">
            <button class="search-dropdown" type="button">
                <i data-lucide="chevron-down" width="14" height="14"></i>
                <span>All Categories</span>
            </button>
            <input class="search-input" placeholder="Search for products..." type="text" />
            <button class="search-submit" type="button" aria-label="Search">
                <i data-lucide="search" width="16" height="16"></i>
            </button>
        </div>

        {{-- Right group: navigation & actions --}}
        <div class="nav-right">
            <div class="nav-divider"></div>

            <div class="icon-group">
                <a href="{{ Route::has('cart') ? route('cart') : '#' }}" class="icon-wrapper" aria-label="Cart">
                    <i data-lucide="shopping-cart" width="20" height="20"></i>
                    <span class="icon-badge">{{ $cartCount }}</span>
                </a>
                <a href="#" class="icon-wrapper" aria-label="Wishlist">
                    <i data-lucide="heart" width="20" height="20"></i>
                </a>
            </div>

            <div class="nav-divider"></div>

            <div class="auth-group">
                @if ($showLoginModal)
                    {{-- Login button - opens modal, optionally hides "Continue as Guest" --}}
                    <a href="#"
                       data-login-trigger
                       @if ($hideGuestInLogin) data-hide-guest="true" @endif
                       class="auth-btn login-btn">
                        Login
                    </a>
                @else
                    {{-- Login link - goes to login page --}}
                    <a href="{{ Route::has('login') ? route('login') : '#' }}" class="auth-btn login-btn">
                        Login
                    </a>
                @endif

                @if ($showRegisterModal)
                    {{-- Register button - opens buyer register modal directly (for Buyer Page) --}}
                    <a href="#" data-buyer-register-trigger class="auth-btn register-btn">
                        Register
                    </a>
                @else
                    {{-- Register link - goes to register type selection page (for Landing Page) --}}
                    <a href="{{ Route::has('register.type') ? route('register.type') : '#' }}" class="auth-btn register-btn">
                        Register
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>