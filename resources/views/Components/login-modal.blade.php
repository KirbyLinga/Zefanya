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
            <img class="brand__logo" src="{{ asset('Images/Zefanya-Logo-128.png') }}" alt="Zefanya logo" />
        </div>

        <h2 class="login-modal__title" id="loginModalTitle">Welcome back</h2>
        <p class="login-modal__subtitle">Log in to continue to Zefanya.</p>

        @if ($errors->any())
            <div class="login-modal__error">
                {{ $errors->first() }}
            </div>
        @endif

        <form class="login-modal__form" method="POST" action="{{ Route::has('login.post') ? route('login.post') : '#' }}">
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

        <div class="login-modal__divider">
            <span class="login-modal__divider-line"></span>
            <span class="login-modal__divider-label">or</span>
            <span class="login-modal__divider-line"></span>
        </div>

        <a href="{{ Route::has('shop.browse') ? route('shop.browse') : '#' }}" class="login-modal__guest">
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

    function openModal(e) {
        if (e) e.preventDefault();
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('[data-login-trigger]').forEach(function (el) {
        el.addEventListener('click', openModal);
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
})();
</script>