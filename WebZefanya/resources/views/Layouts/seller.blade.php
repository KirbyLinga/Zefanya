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

    <x-fonts />

    @vite([
        'resources/css/app.css',
    ])
    @stack('styles')
</head>
<body class="seller-body">
    <a href="#seller-main" class="sr-only fixed left-4 top-4 z-[100] rounded-lg bg-primary-800 px-4 py-2 font-sans text-sm font-semibold text-white focus:not-sr-only">Skip to content</a>
    <div class="flex min-h-screen items-stretch">

        {{-- =========================== SIDEBAR =========================== --}}
        {{-- Modular sidebar. Components live in resources/views/components/seller/
             and are scoped to the seller module via the x-seller.* prefix. --}}
        <x-seller.sidebar />

        {{-- ============================ MAIN ============================= --}}
        <div data-mobile-drawer-content class="flex-1 min-w-0 bg-[var(--bg-page)]">
            <div class="sticky top-0 z-30 flex items-center gap-3 border-b border-[var(--sidebar-border)] bg-[var(--sidebar-bg)] px-4 py-3 lg:hidden">
                <button type="button" id="sellerSidebarOpen" aria-label="Open menu" aria-controls="sellerSidebar" aria-expanded="false"
                    class="flex h-9 w-9 cursor-pointer items-center justify-center rounded-md border-none bg-transparent text-[var(--sidebar-text)] hover:bg-[var(--sidebar-hover)]">
                    <i data-lucide="menu" class="block h-5 w-5"></i>
                </button>
                <span class="font-sans text-[14px] font-semibold text-[var(--sidebar-text)]">Seller Panel</span>
            </div>
            <div id="seller-main" tabindex="-1" class="relative p-4 max-lg:overflow-x-auto lg:p-8">
                <x-seller.sidebar :tabs-only="true" />
                @yield('content')
            </div>
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
    @vite('resources/js/submit-guard.js')
    @vite('resources/js/session-expired.js')
    @vite('resources/js/admin/auto-filters.js')

    @include('Components.confirm-dialog')
    @stack('scripts')
</body>
</html>
