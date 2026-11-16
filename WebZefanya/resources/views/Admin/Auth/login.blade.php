<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login &mdash; Zefanya</title>
    <x-fonts />
    @vite(['resources/css/app.css'])
    <style>
        #admin-login [hidden]{display:none!important}
        #admin-login .remember-label{display:inline-flex;align-items:center;gap:10px;line-height:20px;white-space:nowrap}
        #admin-login #remember{appearance:auto;display:inline-block;flex:0 0 16px;width:16px;height:16px;min-height:0;margin:0;padding:0;vertical-align:middle}
        #admin-login .admin-login__submit,#admin-login .admin-login__submit span,#admin-login .admin-login__submit svg{color:var(--color-white)}
        #admin-login .password-toggle:focus-visible{outline:2px solid var(--color-primary-700);outline-offset:-3px}
    </style>
</head>
<body id="admin-login" class="min-h-screen bg-[var(--bg-page)] font-sans text-[var(--text-body)]">
    <main class="mx-auto flex min-h-screen w-full max-w-[1000px] items-center justify-center p-4 sm:p-6 lg:p-10">
        <div class="grid w-full overflow-hidden rounded-[var(--radius-xl)] border border-[var(--border)] bg-[var(--bg-surface)] shadow-[var(--shadow-lg)] lg:grid-cols-[0.95fr_1fr]">
            <section class="hidden flex-col justify-between gap-8 border-r border-[var(--hero-border)] bg-[image:var(--hero-bg)] p-8 lg:flex" aria-label="Zefanya administration">
                <div>
                    <div class="mb-9 flex items-center gap-3">
                        <img class="h-11 w-11 rounded-full" src="{{ asset('Images/Zefanya-Logo.png') }}" alt="">
                        <span class="font-serif text-[24px] text-[var(--text-ink)]">Zefanya</span>
                    </div>
                    <h2 class="max-w-[12ch] font-serif text-[32px] leading-[1.2] text-[var(--text-ink)]">Your marketplace, in focus.</h2>
                    <p class="mt-4 max-w-[32ch] text-[13px] leading-relaxed text-[var(--text-body)]">Manage applications, orders and people in one place.</p>
                </div>
                <ul class="flex flex-col gap-4">
                    @foreach ([
                        ['shield-check', 'Review applications', 'Sellers and delivery partners.'],
                        ['layout-dashboard', 'Oversee orders', 'Payments and delivery updates.'],
                        ['users', 'Manage accounts', 'Support your marketplace community.'],
                    ] as [$icon, $heading, $copy])
                        <li class="flex items-center gap-3">
                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-[var(--hero-border)] bg-[var(--hero-chip-bg)]"><i data-lucide="{{ $icon }}" width="15" height="15" class="text-primary-700"></i></span>
                            <div><p class="text-[12px] font-semibold text-[var(--text-ink)]">{{ $heading }}</p><p class="mt-0.5 text-[11.5px] text-[var(--text-body)]">{{ $copy }}</p></div>
                        </li>
                    @endforeach
                </ul>
            </section>
            <section class="flex flex-col justify-center gap-6 p-7 sm:p-10">
                <div class="flex items-center gap-3 lg:hidden"><img class="h-9 w-9 rounded-full" src="{{ asset('Images/Zefanya-Logo.png') }}" alt=""><span class="font-serif text-[22px] text-[var(--text-ink)]">Zefanya</span></div>
                <header><h1 class="font-serif text-[28px] leading-tight text-[var(--text-ink)]">Admin Login</h1><p class="mt-2 text-[13px] leading-relaxed text-[var(--text-body)]">Sign in with your staff account.</p></header>
                <div id="adminLoginError" role="alert" tabindex="-1" @if(!$errors->any()) hidden @endif class="rounded-[var(--radius-lg)] border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] px-4 py-3 text-[12.5px] text-[var(--status-danger)]"><p id="adminLoginErrorText" class="text-[var(--status-danger)]">{{ $errors->first() }}</p></div>
                <form id="adminLoginForm" method="POST" action="{{ route('admin.login.post') }}" class="flex flex-col gap-5">
                    @csrf
                    <div class="flex flex-col gap-2">
                        <label for="email" class="text-[12px] font-semibold text-[var(--text-label)]">Email address</label>
                        <div class="relative">
                            <i data-lucide="mail" width="16" height="16" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--text-body)]"></i>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="you@zefanya.shop" required autofocus autocomplete="email" aria-describedby="adminLoginError" @if($errors->has('email')) aria-invalid="true" @endif class="h-12 w-full rounded-[var(--radius-lg)] border border-[var(--border-input)] bg-[var(--bg-surface)] pl-10 pr-4 text-[13px] text-[var(--text-ink)] placeholder:text-[var(--text-body)] focus:border-primary-700 focus:outline-none focus:ring-2 focus:ring-[var(--hover-rose)]">
                        </div>
                    </div>
                    <div class="flex flex-col gap-2">
                        <label for="password" class="text-[12px] font-semibold text-[var(--text-label)]">Password</label>
                        <div class="relative">
                            <i data-lucide="key-round" width="16" height="16" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--text-body)]"></i>
                            <input id="password" name="password" type="password" placeholder="Enter your password" required autocomplete="current-password" aria-describedby="adminLoginError" @if($errors->has('password')) aria-invalid="true" @endif class="h-12 w-full rounded-[var(--radius-lg)] border border-[var(--border-input)] bg-[var(--bg-surface)] pl-10 pr-14 text-[13px] text-[var(--text-ink)] placeholder:text-[var(--text-body)] focus:border-primary-700 focus:outline-none focus:ring-2 focus:ring-[var(--hover-rose)]">
                            <button id="passwordToggle" type="button" aria-controls="password" aria-label="Show password" aria-pressed="false" title="Show password" class="password-toggle absolute right-0.5 top-0.5 flex h-11 w-11 items-center justify-center rounded-lg text-[var(--text-body)] hover:bg-[var(--hover-rose)]">
                                <svg data-password-show aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg data-password-hide hidden aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.2A11.6 11.6 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-3.1 4.1M6.2 6.2A19.3 19.3 0 0 0 2 12s3.5 7 10 7a11 11 0 0 0 5.8-1.7"/></svg>
                            </button>
                        </div>
                    </div>
                    <label for="remember" class="remember-label w-fit cursor-pointer"><input id="remember" name="remember" type="checkbox" value="1" @checked(old('remember')) class="cursor-pointer rounded-[3px] accent-primary-700"><span class="text-[12px] text-[var(--text-body)]">Keep me signed in</span></label>
                    <button id="adminLoginSubmit" type="submit" class="admin-login__submit inline-flex h-12 w-full items-center justify-center gap-2 rounded-[var(--radius-lg)] bg-primary-700 px-6 text-[13px] font-semibold transition-colors hover:bg-primary-800 disabled:cursor-wait disabled:opacity-70"><i data-lucide="log-in" width="16" height="16"></i><span id="adminLoginSubmitText">Log in</span></button>
                </form>
            </section>
        </div>
    </main>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <script>
        if (window.lucide) lucide.createIcons();
        (() => {
            const form = document.getElementById('adminLoginForm');
            const email = document.getElementById('email');
            const password = document.getElementById('password');
            const toggle = document.getElementById('passwordToggle');
            const error = document.getElementById('adminLoginError');
            const errorText = document.getElementById('adminLoginErrorText');
            const submit = document.getElementById('adminLoginSubmit');
            const submitText = document.getElementById('adminLoginSubmitText');
            toggle.addEventListener('click', () => {
                const visible = password.type === 'password';
                password.type = visible ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', String(visible));
                toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
                toggle.title = visible ? 'Hide password' : 'Show password';
                toggle.querySelector('[data-password-show]').hidden = visible;
                toggle.querySelector('[data-password-hide]').hidden = !visible;
            });
            function showError(message) {
                errorText.textContent = message;
                error.hidden = false;
                error.focus({ preventScroll: true });
            }
            form.addEventListener('submit', async event => {
                event.preventDefault();
                if (submit.disabled) return;
                error.hidden = true;
                email.removeAttribute('aria-invalid');
                password.removeAttribute('aria-invalid');
                submit.disabled = true;
                submitText.textContent = 'Signing in...';
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: new FormData(form),
                        credentials: 'same-origin',
                    });
                    if (response.redirected && response.ok && new URL(response.url).origin === window.location.origin) {
                        window.location.assign(response.url);
                    } else if (response.status === 429) {
                        const retry = Number(response.headers.get('Retry-After'));
                        showError(Number.isFinite(retry) && retry > 0 ? `Too many attempts. Try again in ${Math.ceil(retry)} seconds.` : 'Too many attempts. Please wait a minute and try again.');
                    } else if (response.status === 422) {
                        const data = await response.json();
                        if (data.errors?.email) email.setAttribute('aria-invalid', 'true');
                        if (data.errors?.password) password.setAttribute('aria-invalid', 'true');
                        showError(Object.values(data.errors || {}).flat()[0] || data.message || 'Check your email and password.');
                    } else if (response.status === 419) {
                        showError('Your session expired. Refresh the page and try again.');
                    } else {
                        showError('Unable to sign in. Please try again.');
                    }
                } catch (_) {
                    showError('Unable to connect. Check your connection and try again.');
                } finally {
                    submit.disabled = false;
                    submitText.textContent = 'Log in';
                }
            });
        })();
    </script>
</body>
</html>
