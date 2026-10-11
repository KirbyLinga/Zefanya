<!DOCTYPE html>
<html lang="en" data-theme-key="seller.theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Seller Panel') — Zefanya</title>

    {{-- Theme init MUST run before the stylesheet paints: reads THIS panel's own
         persisted theme (localStorage 'seller.theme' — the key is declared once
         on the <html> tag above) and sets data-theme on <html>. 'zf-theme' is
         only the pre-split shared key, read as a one-time fallback so existing
         preferences survive. Default: light. No flash of the wrong theme. --}}
    <script>
        (function () {
            try {
                var key = document.documentElement.dataset.themeKey || 'zf-theme';
                var theme = localStorage.getItem(key) || localStorage.getItem('zf-theme');
                document.documentElement.dataset.theme = (theme === 'dark') ? 'dark' : 'light';
            } catch (e) {
                document.documentElement.dataset.theme = 'light';
            }
        })();
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;800&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">

    @vite([
        'resources/css/app.css',
    ])
    @stack('styles')
</head>
<body class="seller-body">
    <div class="flex min-h-screen items-stretch">

        {{-- =========================== SIDEBAR =========================== --}}
        {{-- Modular sidebar. Components live in resources/views/components/seller/
             and are scoped to the seller module via the x-seller.* prefix. --}}
        <x-seller.sidebar />

        {{-- ============================ MAIN ============================= --}}
        <div class="flex-1 min-w-0 p-8 bg-[var(--bg-page)]">
            @yield('content')
        </div>
    </div>

    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <script>
        lucide.createIcons();
    </script>

    {{-- Light/dark toggle behavior — loaded here (not per page) so the
         sidebar toggle works on every seller page. --}}
    @vite('resources/js/seller/theme-toggle.js')
    @vite('resources/js/seller/sidebar-tooltips.js')

    @stack('scripts')
</body>
</html>
