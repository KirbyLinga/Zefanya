<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title', 'Zefanya Admin')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">
    {{-- ADMIN-d: app.css only. admin.css was DELETED — every class it defined
         (shell/sidebar/dash-summary/admin-login) became unused once its views
         converted in ADMIN-b/c, and its design-system import was already gone
         (ADMIN-b Hazard #1 fix). --}}
    @vite(['resources/css/app.css'])

    @stack('styles')
</head>
{{-- admin-body → utilities (ADMIN-b): margin 0 = preflight, min-height 100vh,
     background var(--bg-page) = --color-primary-50. --}}
<body class="min-h-screen bg-primary-50">

    {{-- admin-shell → utilities: display:flex, min-height:100vh --}}
    <div class="flex min-h-screen">

        @include('Components.admin-sidebar')

        {{-- admin-main → utilities: flex column, flex:1, min-width:0 --}}
        <div class="flex flex-1 min-w-0 flex-col">
            {{-- admin-topbar → utilities: padding var(--space-4) var(--space-8),
                 bg-surface = #fff, border-bottom 1px var(--border-light) =
                 neutral-200 --}}
            <header class="flex items-center justify-between px-8 py-4 bg-white border-b border-neutral-200">
                <span class="font-sans font-semibold text-[15px] tracking-[0.5px] uppercase text-neutral-950">@yield('title', 'Dashboard')</span>
                @if (Auth::guard('admin')->check())
                    <span class="font-sans font-medium text-[13px] text-neutral-600">{{ Auth::guard('admin')->user()->name }}</span>
                @endif
            </header>

            {{-- admin-content → utilities: flex:1, padding var(--space-8) --}}
            <main class="flex-1 p-8">
                @yield('content')
            </main>
        </div>

    </div>

    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <script>lucide.createIcons();</script>
    @stack('scripts')
</body>
</html>