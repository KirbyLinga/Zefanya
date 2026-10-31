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
            {{-- Only controls that work: today's date and this admin's own account menu. --}}
            @php
                $topBarAdmin = auth('admin')->user();
            @endphp
            <header data-admin-topbar class="flex min-h-16 items-center justify-between gap-4 border-b border-neutral-200 bg-white px-4 sm:px-8">
                <p class="font-sans text-[13px] text-neutral-600">{{ now(\App\Models\Seller\StoreSale::TIMEZONE)->format('l, F j, Y') }}</p>
                <details class="group relative">
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-3 rounded-lg px-2 transition-colors hover:bg-neutral-100 [&::-webkit-details-marker]:hidden">
                        <span class="hidden flex-col items-end sm:flex">
                            <span class="text-sm font-semibold text-neutral-950">{{ $topBarAdmin?->name ?? 'Admin' }}</span>
                            <span class="text-xs text-neutral-600">{{ \Illuminate\Support\Str::headline($topBarAdmin?->role ?? 'admin') }}</span>
                        </span>
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary-800 font-sans text-sm font-semibold text-white">{{ mb_strtoupper(mb_substr($topBarAdmin?->name ?? 'A', 0, 1)) }}</span>
                        <i data-lucide="chevron-down" class="size-4 text-neutral-600 transition-transform group-open:rotate-180" aria-hidden="true"></i>
                    </summary>
                    <div class="absolute right-0 z-30 mt-2 w-56 rounded-lg border border-neutral-200 bg-white p-1.5 shadow-lg">
                        <a href="{{ route('admin.two-factor.show') }}" class="flex min-h-11 items-center gap-2 rounded-md px-3 font-sans text-[13px] text-neutral-950 no-underline hover:bg-neutral-100">
                            <i data-lucide="shield-check" class="size-4 shrink-0" aria-hidden="true"></i>Two-factor security
                        </a>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit" class="flex min-h-11 w-full cursor-pointer items-center gap-2 rounded-md px-3 text-left font-sans text-[13px] text-neutral-950 hover:bg-neutral-100">
                                <i data-lucide="log-out" class="size-4 shrink-0" aria-hidden="true"></i>Log out
                            </button>
                        </form>
                    </div>
                </details>
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