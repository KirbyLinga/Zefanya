<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title', 'Zefanya Admin')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])

    @stack('styles')
</head>
<body class="min-h-screen bg-primary-50">

    <div class="flex min-h-screen">

        @include('Components.admin-sidebar')

        <div class="flex flex-1 min-w-0 flex-col">
            {{-- Admin Top Bar --}}
            <header class="flex items-center justify-between px-8 py-4 bg-white border-b border-neutral-200">
                {{-- Left: Global Search --}}
                <div class="flex-1 max-w-md">
                    <input type="text" placeholder="Search accounts, email or user ID…"
                           class="w-full h-10 px-4 border border-neutral-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-400 focus:border-transparent" />
                </div>

                {{-- Right: Notifications, Help, User --}}
                <div class="flex items-center gap-6">
                    {{-- Notifications Bell --}}
                    <button class="relative flex items-center justify-center w-10 h-10 rounded-lg hover:bg-neutral-100 transition-colors">
                        <i data-lucide="bell" class="w-5 h-5 text-neutral-600"></i>
                        <span class="absolute top-2 right-2 w-2 h-2 bg-red-500 rounded-full"></span>
                    </button>

                    {{-- Help Icon --}}
                    <button class="flex items-center justify-center w-10 h-10 rounded-lg hover:bg-neutral-100 transition-colors">
                        <i data-lucide="help-circle" class="w-5 h-5 text-neutral-600"></i>
                    </button>

                    {{-- User Block --}}
                    <button class="flex items-center gap-3 pl-4 pr-2 py-2 rounded-lg hover:bg-neutral-100 transition-colors">
                        <div class="flex flex-col items-end">
                            <span class="text-sm font-semibold text-neutral-950">Zefanya Admin</span>
                            <span class="text-xs text-neutral-600">Super Admin</span>
                        </div>
                        <div class="w-8 h-8 rounded-full bg-primary-200 flex items-center justify-center">
                            <i data-lucide="user" class="w-4 h-4 text-primary-800"></i>
                        </div>
                        <i data-lucide="chevron-down" class="w-4 h-4 text-neutral-600"></i>
                    </button>
                </div>
            </header>

            {{-- Main Content --}}
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