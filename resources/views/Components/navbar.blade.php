{{-- resources/views/Components/navbar.blade.php
     Usage: @include('Components.navbar', [
         'cartCount' => 3,               // optional, defaults to 0
     ]) --}}
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
                    <span class="icon-badge">{{ $cartCount ?? 0 }}</span>
                </a>
                <a href="#" class="icon-wrapper" aria-label="Wishlist">
                    <i data-lucide="heart" width="20" height="20"></i>
                </a>
            </div>

            <div class="nav-divider"></div>

            <div class="auth-group">
                <a href="#" data-login-trigger>Login</a>
                <a href="{{ Route::has('register.type') ? route('register.type') : '#' }}">Register</a>
            </div>
        </div>
    </div>
</div>