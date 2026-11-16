<!DOCTYPE html>
<html lang="en" data-theme-key="rider.theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Rider Panel') — Zefanya</title>

    {{-- Theme init MUST run before the stylesheet paints: reads THIS panel's own
         persisted theme (localStorage 'rider.theme') and sets data-theme on
         <html>. Mirrors Layouts/logistics.blade.php. --}}
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

    @vite(['resources/css/app.css'])
    @stack('styles')
</head>
<body class="logistics-body">
    <a href="#rider-main" class="sr-only fixed left-4 top-4 z-[100] rounded-lg bg-primary-800 px-4 py-2 font-sans text-sm font-semibold text-white focus:not-sr-only">Skip to content</a>
    <div class="flex min-h-screen items-stretch">

        {{-- =========================== SIDEBAR =========================== --}}
        <x-rider.sidebar />

        {{-- ============================ MAIN ============================= --}}
        <div id="rider-main" tabindex="-1" class="flex-1 min-w-0 max-sm:ml-[72px] p-4 sm:p-6 lg:p-8 bg-[var(--bg-page)]">
            <div class="mb-4 flex items-center justify-end gap-2">
                <button
                    type="button"
                    data-theme-toggle
                    class="sp-icon-btn zf-theme-toggle"
                    aria-label="Switch to dark mode"
                    aria-pressed="false"
                >
                    <i data-lucide="moon" class="zf-icon-moon block h-[18px] w-[18px]" aria-hidden="true"></i>
                    <i data-lucide="sun" class="zf-icon-sun block h-[18px] w-[18px]" aria-hidden="true"></i>
                </button>

                <x-rider.notification-bell
                    :notifications="$riderBellNotifications ?? collect()"
                    :unread-count="$riderBellUnreadCount ?? 0"
                />
            </div>

            @yield('content')
        </div>
    </div>

    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <script>
        lucide.createIcons();
    </script>

    @vite('resources/js/seller/theme-toggle.js')
    @vite('resources/js/rider/sidebar-tooltips.js')
    @vite('resources/js/submit-guard.js')
    @vite('resources/js/session-expired.js')
    @vite('resources/js/rider/notification-bell.js')

    @include('Components.confirm-dialog')
    @stack('scripts')
</body>
</html>
