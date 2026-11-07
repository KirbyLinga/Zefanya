<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title', 'Zefanya Admin')</title>

    <x-fonts />
    @vite(['resources/css/app.css'])

    @stack('styles')
</head>
<body class="min-h-screen bg-primary-50">
    <a href="#adminMain" class="sr-only rounded-lg bg-primary-800 px-4 py-2 font-sans text-[13px] font-semibold text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60]">Skip to content</a>

    <div class="flex min-h-screen">

        @include('Components.admin-sidebar')

        <div class="flex flex-1 min-w-0 flex-col">
            {{-- Main Content --}}
            <main id="adminMain" tabindex="-1" class="flex-1 p-4 focus:outline-none sm:p-6 lg:p-8">
                <div class="mx-auto w-full @yield('content-width', 'max-w-[1400px]')">
                    @yield('content')
                </div>
            </main>
        </div>

    </div>

    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <script>lucide.createIcons();</script>
    @vite('resources/js/submit-guard.js')
    @stack('scripts')
</body>
</html>