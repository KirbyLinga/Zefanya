<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Two-Factor Verification — Zefanya</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[var(--bg-page)] font-sans text-[var(--text-body)]">

    <main class="mx-auto flex min-h-screen w-full max-w-[560px] items-center justify-center p-4 sm:p-6">
        <div class="w-full rounded-[var(--radius-xl)] border border-[var(--border)] bg-[var(--bg-surface)] p-7 shadow-[var(--shadow-lg)] sm:p-10">

            <div class="flex items-center gap-3">
                <img class="h-10 w-10 rounded-full" src="{{ asset('Images/Zefanya-Logo.png') }}" alt="Zefanya logo" />
                <span class="flex flex-col">
                    <span class="font-serif text-[17px] text-[var(--text-ink)]">Zefanya</span>
                    <span class="font-sans text-[10.5px] font-semibold uppercase tracking-[1.2px] text-[var(--text-muted)]">Admin Console</span>
                </span>
            </div>

            <header class="mt-6 flex flex-col gap-2">
                <span class="font-sans text-[10.5px] font-semibold uppercase tracking-[1.4px] text-[var(--link)]">Second step</span>
                <h1 class="font-serif text-[26px] leading-tight text-[var(--text-ink)]">Two-factor verification</h1>
                <p class="font-sans text-[13px] leading-relaxed text-[var(--text-muted)]">
                    Enter the 6-digit code from your authenticator app, or one of your recovery codes.
                </p>
            </header>

            @if ($errors->any())
                <div role="alert" class="mt-5 flex items-start gap-2.5 rounded-[var(--radius-lg)] border border-[var(--border-error-soft)] bg-[var(--bg-error-soft)] px-4 py-3">
                    <i data-lucide="alert-circle" width="15" height="15" class="mt-px shrink-0 text-[var(--status-danger)]"></i>
                    <span class="font-sans text-[12.5px] font-medium text-[var(--status-danger)]">{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.two-factor.verify') }}" class="mt-6 flex flex-col gap-5">
                @csrf

                <div class="flex flex-col gap-2">
                    <label for="code" class="font-sans text-[11px] font-semibold uppercase tracking-[0.8px] text-[var(--text-label)]">Authentication code</label>
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                           value="{{ old('code') }}" required autofocus
                           placeholder="123456 or recovery code"
                           class="h-11 w-full rounded-[var(--radius-lg)] border border-[var(--border-input)] bg-[var(--bg-surface)] px-4 font-sans text-[13.5px] text-[var(--text-ink)] transition-colors duration-150 placeholder:text-[var(--text-placeholder)] focus:border-[var(--brand)] focus:outline-none focus:ring-2 focus:ring-[var(--hover-rose)]" />
                </div>

                <button type="submit"
                        class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-[var(--radius-lg)] bg-[var(--btn-primary-bg)] px-6 font-sans text-[12.5px] font-semibold uppercase tracking-[1.4px] text-[var(--btn-primary-text)] transition-colors duration-150 hover:bg-[var(--btn-primary-bg-hover)]">
                    <i data-lucide="shield-check" width="15" height="15"></i>
                    <span>Verify and continue</span>
                </button>
            </form>

            <p class="mt-6 border-t border-[var(--border)] pt-5 font-sans text-[11.5px] leading-relaxed text-[var(--text-muted)]">
                Lost your authenticator? Use a recovery code. If none are available, contact the system owner to reset your account.
            </p>
        </div>
    </main>

    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <script>
        if (window.lucide) { lucide.createIcons(); }
    </script>
</body>
</html>
