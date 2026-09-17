{{-- Seller-scoped logout form. Submits the unified logout route so it also
     clears the buyer guard on shared sessions. --}}

<form method="POST" action="{{ route('unified.logout') }}" class="seller-nav__logout-form">
    @csrf
    <button type="submit" class="seller-nav__link seller-nav__link--logout">
        <i data-lucide="log-out" width="18" height="18" class="seller-nav__icon"></i>
        <span>Log out</span>
    </button>
</form>
