<!DOCTYPE html>
<html lang="en" data-theme-key="logistics.theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Logistics Panel') — Zefanya</title>

    {{-- Theme init MUST run before the stylesheet paints: reads THIS panel's own
         persisted theme (localStorage 'logistics.theme' — the key is declared
         once on the <html> tag above) and sets data-theme on <html>. 'zf-theme'
         is only the pre-split shared key, read as a one-time fallback so
         existing preferences survive. Default: light. Mirrors Layouts/seller. --}}
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
<body class="logistics-body">
    <div class="flex min-h-screen items-stretch">

        {{-- =========================== SIDEBAR =========================== --}}
        {{-- Modular sidebar. Components live in resources/views/components/logistics/
             and are scoped to the logistics module via the x-logistics.* prefix.
             Structure mirrors x-seller.sidebar 1:1. --}}
        <x-logistics.sidebar />

        {{-- ============================ MAIN ============================= --}}
        <div class="flex-1 min-w-0 p-8 bg-[var(--bg-page)]">
            @yield('content')
        </div>
    </div>

    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <script>
        lucide.createIcons();
    </script>

    {{-- Light/dark toggle + collapsed-sidebar tooltips — loaded here (not per
         page) so the sidebar behaves on every logistics page. Mirrors the
         seller layout's script loading. --}}
    @vite('resources/js/seller/theme-toggle.js')
    @vite('resources/js/logistics/sidebar-tooltips.js')

    @stack('scripts')
</body>
</html>
