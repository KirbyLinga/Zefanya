<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin Login — Zefanya</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">
    {{-- ADMIN-b (scope note: pulled forward from ADMIN-d). This standalone page
         was the ONLY consumer of design-system.css's `.input` / `.btn--inverted`
         component classes, so it had to convert in the same chunk that removed
         the design-system @import from admin.css, or its inputs/buttons would
         have lost their styling. Now self-sufficient on app.css alone. --}}
    @vite(['resources/css/app.css'])
</head>
{{-- admin-login-body → utilities (ADMIN-b): margin 0 = preflight,
     min-height 100vh, centered flex, bg var(--bg-page) = primary-50,
     padding var(--space-6) = 24px. --}}
<body class="min-h-screen flex items-center justify-center bg-primary-50 p-6">

    {{-- admin-login-card → utilities: max-w 400px, bg-surface = #fff,
         border var(--border-light) = neutral-200, radius-lg = 8px,
         padding 40px 32px 32px, shadow-lg. --}}
    <div class="w-full max-w-[400px] bg-white border border-neutral-200 rounded-lg pt-10 px-8 pb-8 shadow-lg">
        <img class="block w-12 h-12 rounded-full mx-auto mb-5" src="{{ asset('Images/Zefanya-Logo.png') }}" alt="Zefanya logo" />
        <h1 class="font-serif font-normal text-[24px] text-neutral-950 text-center">Admin Login</h1>
        <p class="font-sans text-[13.5px] text-neutral-600 text-center mt-2">Restricted access — Zefanya staff only.</p>

        @if ($errors->any())
            <div class="mt-4 py-3 px-4 bg-[#fbe9e7] border border-[#e8a49c] rounded-sm font-sans text-[12.5px] text-[#a5333d]">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.login.post') }}">
            @csrf>

            {{-- design-system `.admin-login-card__label`: 11px 600 uppercase,
                 tracking 0.8px, mb 8px mt 16px; :first-of-type mt 24px. --}}
            <label class="block font-sans font-semibold text-[11px] tracking-[0.8px] uppercase text-neutral-600 mt-4 mb-2 first-of-type:mt-6" for="email">Email address</label>
            {{-- design-system `.input` → utilities, 1:1: h 44px, px 16px,
                 border var(--border-default) = neutral-300, radius-sm = 4px,
                 focus border var(--border-focus) = primary-400,
                 placeholder var(--text-muted) = neutral-500. --}}
            <input class="w-full h-11 px-4 bg-white border border-neutral-300 rounded-sm font-sans text-sm text-neutral-950 transition-colors duration-200 placeholder:text-neutral-500 focus:outline-none focus:border-primary-400" type="email" name="email" id="email" value="{{ old('email') }}" required autofocus />

            <label class="block font-sans font-semibold text-[11px] tracking-[0.8px] uppercase text-neutral-600 mt-4 mb-2 first-of-type:mt-6" for="password">Password</label>
            <input class="w-full h-11 px-4 bg-white border border-neutral-300 rounded-sm font-sans text-sm text-neutral-950 transition-colors duration-200 placeholder:text-neutral-500 focus:outline-none focus:border-primary-400" type="password" name="password" id="password" required />

            <label class="flex items-center gap-2 mt-4 font-sans text-[12.5px] text-neutral-600 cursor-pointer">
                <input type="checkbox" name="remember" class="accent-primary-700" />
                <span>Remember me</span>
            </label>

            {{-- design-system `.btn .btn--inverted` → utilities, 1:1:
                 h 44px, px 24px, radius-sm, 12.5px 600, tracking 1.4px,
                 bg neutral-950 → hover neutral-900, color --text-inverse
                 (#fff8f7 = cream token). --}}
            <button class="inline-flex items-center justify-center gap-2 h-11 px-6 rounded-sm font-sans font-semibold text-[12.5px] tracking-[1.4px] cursor-pointer bg-neutral-950 text-cream transition-colors duration-200 hover:bg-neutral-900" type="submit">LOG IN</button>
        </form>
    </div>

</body>
</html>