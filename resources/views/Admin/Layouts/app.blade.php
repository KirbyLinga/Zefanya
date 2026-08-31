<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title', 'Zefanya Admin')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">
    @vite(['resources/css/admin/admin.css'])

    @stack('styles')
</head>
<body class="admin-body">

    <div class="admin-shell">

        @include('Components.admin-sidebar')

        <div class="admin-main">
            <header class="admin-topbar">
                <span class="admin-topbar__title">@yield('title', 'Dashboard')</span>
                @if (Auth::guard('admin')->check())
                    <span class="admin-topbar__user">{{ Auth::guard('admin')->user()->name }}</span>
                @endif
            </header>

            <main class="admin-content">
                @yield('content')
            </main>
        </div>

    </div>

    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <script>lucide.createIcons();</script>
    @stack('scripts')
</body>
</html>