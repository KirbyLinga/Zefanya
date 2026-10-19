<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin Login — Zefanya</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">
    {{-- Standalone page: app.css only (it imports theme.css). Every color on this
         page is a theme token — either a @theme utility (primary/secondary/
         tertiary/neutral) or a theme.css custom property consumed as an arbitrary
         value (`bg-[var(--bg-surface)]`, the convention Buyer/Orders/* already
         uses). No raw hex and no color that is absent from the theme. --}}
    @vite(['resources/css/app.css'])
</head>
{{-- body: page canvas = --bg-page (theme's light page tint; the dark value lives
     in theme.css under html[data-theme="dark"], but this shell is light-only). --}}
<body class="min-h-screen bg-[var(--bg-page)] font-sans text-[var(--text-body)]">

    {{-- Centered canvas — single column on mobile, split card from lg up. --}}
    <main class="mx-auto flex min-h-screen w-full max-w-[1040px] items-center justify-center p-4 sm:p-6 lg:p-10">

        {{-- Login card: --bg-surface / --border / theme radius + shadow tokens.
             Left = brand panel (lg+), right = the form. --}}
        <div class="grid w-full overflow-hidden rounded-[var(--radius-xl)] border border-[var(--border)] bg-[var(--bg-surface)] shadow-[var(--shadow-lg)] lg:grid-cols-[1.05fr_1fr]">

            {{-- ===== Brand panel — lg and up only =====
                 Background is the theme's dashboard-hero gradient; the chips and
                 icon discs reuse the hero chip/border tokens. --}}
            <section class="hidden flex-col justify-between gap-10 border-r border-[var(--hero-border)] bg-[image:var(--hero-bg)] p-10 lg:flex">

                <div class="flex flex-col gap-7">
                    <span class="inline-flex w-fit items-center gap-2 rounded-full border border-[var(--hero-border)] bg-[var(--hero-chip-bg)] px-3.5 py-1.5">
                        <i data-lucide="lock" width="13" height="13" class="text-[var(--link)]"></i>
                        <span class="font-sans text-[10.5px] font-semibold uppercase tracking-[1.4px] text-[var(--text-label)]">Admin Console</span>
                    </span>

                    <div class="flex items-center gap-3">
                        <img class="h-11 w-11 rounded-full" src="{{ asset('Images/Zefanya-Logo.png') }}" alt="" />
                        <span class="flex flex-col">
                            <span class="font-serif text-[19px] text-[var(--text-ink)]">Zefanya</span>
                            <span class="font-sans text-[10.5px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Marketplace Administration</span>
                        </span>
                    </div>

                    <h1 class="font-serif text-[34px] leading-[1.15] text-[var(--text-ink)]">Run the marketplace with confidence.</h1>
                    <p class="max-w-[44ch] font-sans text-[13.5px] leading-relaxed text-[var(--text-body)]">
                        Review seller and logistics applications, watch orders move, and keep Zefanya
                        running smoothly — all from one console.
                    </p>
                </div>

                {{-- Static capability list (no data, no links). --}}
                <ul class="flex flex-col gap-5">
                    @foreach ([
                        ['shield-check', 'Verify applications', 'Approve or reject sellers, couriers and logistics partners.'],
                        ['layout-dashboard', 'Oversee operations', 'Orders, payments and delivery health in a single view.'],
                        ['users', 'Support the community', 'Buyers, sellers and couriers across every region.'],
                    ] as [$icon, $heading, $copy])
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-[var(--hero-border)] bg-[var(--hero-chip-bg)]">
                                <i data-lucide="{{ $icon }}" width="15" height="15" class="text-[var(--link)]"></i>
                            </span>
                            <span class="flex flex-col gap-0.5">
                                <span class="font-sans text-[12.5px] font-semibold text-[var(--text-ink)]">{{ $heading }}</span>
                                <span class="font-sans text-[12px] leading-relaxed text-[var(--text-muted)]">{{ $copy }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- ===== Form panel ===== --}}
            <section class="flex flex-col justify-center gap-6 p-7 sm:p-10">

                {{-- Compact brand row — stands in for the panel above below lg. --}}
                <div class="flex items-center gap-3 lg:hidden">
                    <img class="h-10 w-10 rounded-full" src="{{ asset('Images/Zefanya-Logo.png') }}" alt="Zefanya logo" />
                    <span class="flex flex-col">
                        <span class="font-serif text-[17px] text-[var(--text-ink)]">Zefanya</span>
                        <span class="font-sans text-[10.5px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Admin Console</span>
                    </span>
                </div>

                <header class="flex flex-col gap-2">
                    <span class="font-sans text-[10.5px] font-semibold uppercase tracking-[1.4px] text-[var(--link)]">Restricted area</span>
                    <h2 class="font-serif text-[26px] leading-tight text-[var(--text-ink)]">Admin Login</h2>
                    <p class="font-sans text-[13px] leading-relaxed text-[var(--text-muted)]">
                        Zefanya staff only. Sign in with your administrator credentials to continue.
                    </p>
                </header>

                @if ($errors->any())
                    {{-- Danger surface: --bg-error-soft / --border-error-soft / --status-danger
                         (theme.css tokens, each with a dark value too — no raw hex). --}}
                    <div id="adminLoginError" role="alert"
                         class="flex items-start gap-2.5 rounded-[var(--radius-lg)] border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] px-4 py-3">
                        <i data-lucide="alert-circle" width="15" height="15" class="mt-px shrink-0 text-[var(--status-danger)]"></i>
                        <span class="font-sans text-[12.5px] font-medium text-[var(--status-danger)]">{{ $errors->first() }}</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.login.post') }}" class="flex flex-col gap-5">
                    @csrf

                    <div class="flex flex-col gap-2">
                        <label for="email" class="font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-[var(--text-label)]">Email address</label>
                        <div class="relative">
                            <i data-lucide="mail" width="15" height="15" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--text-placeholder)]"></i>
                            <input id="email" name="email" type="email" value="{{ old('email') }}"
                                   placeholder="you@zefanya.shop" required autofocus autocomplete="email"
                                   @if ($errors->any()) aria-describedby="adminLoginError" @endif
                                   class="h-11 w-full rounded-[var(--radius-lg)] border border-[var(--border-input)] bg-[var(--bg-surface)] pl-10 pr-4 font-sans text-[13.5px] text-[var(--text-ink)] transition-colors duration-150 placeholder:text-[var(--text-placeholder)] focus:border-[var(--brand)] focus:outline-none focus:ring-2 focus:ring-[var(--hover-rose)]" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="password" class="font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-[var(--text-label)]">Password</label>
                        <div class="relative">
                            <i data-lucide="key-round" width="15" height="15" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--text-placeholder)]"></i>
                            <input id="password" name="password" type="password" required autocomplete="current-password"
                                   @if ($errors->any()) aria-describedby="adminLoginError" @endif
                                   class="h-11 w-full rounded-[var(--radius-lg)] border border-[var(--border-input)] bg-[var(--bg-surface)] pl-10 pr-4 font-sans text-[13.5px] text-[var(--text-ink)] transition-colors duration-150 placeholder:text-[var(--text-placeholder)] focus:border-[var(--brand)] focus:outline-none focus:ring-2 focus:ring-[var(--hover-rose)]" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <label for="remember" class="inline-flex w-fit cursor-pointer items-center gap-2.5">
                            <input id="remember" name="remember" type="checkbox"
                                   class="h-4 w-4 cursor-pointer rounded-[3px] border border-[var(--border-input)] accent-[var(--brand)]" />
                            <span class="font-sans text-[12.5px] text-[var(--text-body)]">Keep me signed in</span>
                        </label>
                        <span class="font-sans text-[11.5px] text-[var(--text-muted)]">Lost your password? Ask a fellow administrator.</span>
                    </div>

                    {{-- .admin-login__submit swaps the base layer's hardcoded blue
                         button focus ring for a --brand one (app.css @layer base —
                         see the note there for why it cannot live in components). --}}
                    <button type="submit"
                            class="admin-login__submit inline-flex h-11 w-full items-center justify-center gap-2 rounded-[var(--radius-lg)] bg-[var(--btn-primary-bg)] px-6 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-[var(--btn-primary-text)] transition-colors duration-150 hover:bg-[var(--btn-primary-bg-hover)]">
                        <i data-lucide="log-in" width="15" height="15"></i>
                        <span class="text-[var(--btn-primary-text)]">Log in</span>
                    </button>
                </form>

                <p class="flex items-start gap-2 border-t border-[var(--border)] pt-5">
                    <i data-lucide="history" width="13" height="13" class="mt-0.5 shrink-0 text-[var(--text-placeholder)]"></i>
                    <span class="font-sans text-[11.5px] leading-relaxed text-[var(--text-muted)]">
                        Admin accounts are created by the system owner — there is no self-service signup.
                    </span>
                </p>
            </section>
        </div>
    </main>

    {{-- Same lucide bootstrap the admin layout / seller / logistics shells use. --}}
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <script>
        if (window.lucide) { lucide.createIcons(); }
    </script>
</body>
</html>