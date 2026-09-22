<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Logistics · Zefanya')</title>

    {{-- Theme init MUST run before the stylesheet paints: reads the persisted
         theme (localStorage 'zf-theme') and sets data-theme on <html>.
         Default: light. No flash of the wrong theme. Mirrors Layouts/seller.blade.php. --}}
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('zf-theme');
                document.documentElement.dataset.theme = (theme === 'dark') ? 'dark' : 'light';
            } catch (e) {
                document.documentElement.dataset.theme = 'light';
            }
        })();
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;800&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/css/logistics/dashboard.css'])
    @stack('styles')
</head>
<body class="lg-body">
<div class="lg-shell">
    @include('Components.logistics.sidebar')

    <div class="lg-main">
        @include('Components.logistics.topbar')
        <main class="lg-content" id="lgMain" tabindex="-1">
            @yield('content')
        </main>
    </div>
</div>

    {{-- lucide powers the sidebar / table icon set — mirrors the seller layout,
         which loads js/lucide.min.js before its scripts run. --}}
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    @vite('resources/js/logistics/dashboard.js')
    @stack('scripts')
</body>
</html>
