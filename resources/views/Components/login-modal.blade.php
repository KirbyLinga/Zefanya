{{-- resources/views/Components/login-modal.blade.php
     Include once, near the end of the layout body (after navbar), e.g. in Layouts/app.blade.php:
       @include('Components.login-modal')
     Trigger it from anywhere with:
       <a href="#" data-login-trigger>Login</a>
     (the navbar's Login link already gets a data-login-trigger attribute below) --}}

<div class="login-modal-overlay" id="loginModalOverlay" aria-hidden="true">
    <div class="login-modal" role="dialog" aria-modal="true" aria-labelledby="loginModalTitle">

        <button type="button" class="login-modal__close" id="loginModalClose" aria-label="Close login form">
            <i data-lucide="x" width="18" height="18"></i>
        </button>

        <div class="login-modal__brand">
            <img class="brand__logo" src="{{ asset('Images/Zefanya-Logo.png') }}" alt="Zefanya logo" />
        </div>

        <h2 class="login-modal__title" id="loginModalTitle">Welcome back</h2>
        <p class="login-modal__subtitle">Log in to continue to Zefanya.</p>

        @if (session('auth.status_message'))
            <div class="login-modal__error">
                {{ session('auth.status_message') }}
                @if (session('auth.resume_url'))
                    <div><a href="{{ session('auth.resume_url') }}" style="text-decoration: underline;">{{ session('auth.resume_label') ?? 'Verify your email' }}</a></div>
                @endif
            </div>
        @elseif ($errors->any())
            <div class="login-modal__error">
                {{ $errors->first() }}
            </div>
        @endif

        <form class="login-modal__form" method="POST" action="{{ route('unified.login') }}">
            @csrf

            <label class="login-modal__label" for="loginEmail">Email address</label>
            <input
                class="login-modal__input"
                type="email"
                name="email"
                id="loginEmail"
                placeholder="you@example.com"
                value="{{ old('email') }}"
                required
                autofocus
            />

            <label class="login-modal__label" for="loginPassword">Password</label>
            <div class="login-modal__password-wrap">
                <input
                    class="login-modal__input"
                    type="password"
                    name="password"
                    id="loginPassword"
                    placeholder="••••••••"
                    required
                />
                <button type="button" class="login-modal__toggle-visibility" id="togglePassword" aria-label="Show password">
                    <i data-lucide="eye" width="18" height="18"></i>
                </button>
            </div>

            <div class="login-modal__row">
                <label class="login-modal__remember">
                    <input type="checkbox" name="remember" />
                    <span>Remember me</span>
                </label>
                <a href="{{ Route::has('password.request') ? route('password.request') : '#' }}" class="login-modal__forgot">Forgot password?</a>
            </div>

            <button type="submit" class="login-modal__submit">LOG IN</button>
        </form>

        <div class="login-modal__divider" id="loginModalDivider">
            <span class="login-modal__divider-line"></span>
            <span class="login-modal__divider-label">or</span>
            <span class="login-modal__divider-line"></span>
        </div>

        {{-- Google Sign-In. One role-agnostic button: the callback resolves
             buyer | seller | logistics from the Google email. Accounts are
             never created here — an unknown email comes back to this login
             screen with an error. --}}
        <a href="{{ route('auth.google.redirect') }}" class="login-modal__google" id="loginModalGoogle">
            <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" focusable="false">
                <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.91c1.7-1.57 2.69-3.88 2.69-6.62Z" />
                <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.91-2.26c-.81.54-1.84.86-3.05.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18Z" />
                <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33Z" />
                <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58Z" />
            </svg>
            CONTINUE WITH GOOGLE
        </a>

        <a href="{{ route('buyer.home') }}" class="login-modal__guest" id="loginModalGuest">
            CONTINUE AS GUEST
        </a>

        <p class="login-modal__footer">
            Don't have an account?
            <a href="{{ Route::has('register.type') ? route('register.type') : '#' }}">Register</a>
        </p>

    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('loginModalOverlay');
    if (!overlay) return;

    var closeBtn = document.getElementById('loginModalClose');
    var toggleBtn = document.getElementById('togglePassword');
    var passwordInput = document.getElementById('loginPassword');
    var loginForm = overlay.querySelector('.login-modal__form');
    var divider = document.getElementById('loginModalDivider');
    var guestLink = document.getElementById('loginModalGuest');

    function openModal(e, options) {
        if (e) e.preventDefault();
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        // If options.hideGuest is true, hide the "Continue as Guest" section
        if (options && options.hideGuest && divider && guestLink) {
            divider.style.display = 'none';
            guestLink.style.display = 'none';
        }
    }

    function closeModal() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        // Discard any pending cart/Checkout action so a stale Buy Now or
        // Add-to-Cart does not fire later from a different page.
        if (typeof window.clearPendingAction === 'function') {
            window.clearPendingAction();
        }
    }

    // Expose openModal globally so addToCart() can trigger it
    // Supports optional options object: { hideGuest: true }
    window.openLoginModal = function (e, options) {
        openModal(e, options);
    };

    document.querySelectorAll('[data-login-trigger]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            // Check if the trigger element has data-hide-guest attribute
            var hideGuest = el.getAttribute('data-hide-guest') === 'true';
            openModal(e, { hideGuest: hideGuest });
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeModal);

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
    });

    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', function () {
            var isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            toggleBtn.innerHTML = '<i data-lucide="' + (isPassword ? 'eye-off' : 'eye') + '" width="18" height="18"></i>';
            if (window.lucide) lucide.createIcons();
        });
    }

    // Direct links, auth redirects and failed submissions all use this modal.
    if (@json(request()->boolean('login') || session('auth.open_login_modal') || session('auth.status_message'))) {
        openModal();
        if (window.lucide) window.lucide.createIcons();
    }

    // Success callback — called after successful login/register.
    // The login form submits via classic POST (page reload), so this fires
    // on the next page load if a pending cart action exists.
    window.addEventListener('load', function () {
        if (typeof retryPendingCartAction === 'function') {
            retryPendingCartAction();
        }
    });
})();
</script>