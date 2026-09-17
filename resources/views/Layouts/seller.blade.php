<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Seller Panel') — Zefanya</title>

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;800&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">

    @vite([
        'resources/css/design-system.css',
        'resources/css/seller/seller-app.css',
        'resources/css/seller/seller-products.css',
        'resources/css/seller/seller-dashboard.css',
    ])
    @stack('styles')
</head>
<body class="seller-body">
    <div class="seller-layout">

        {{-- =========================== SIDEBAR =========================== --}}
        {{-- Modular sidebar. Components live in resources/views/components/seller/
             and are scoped to the seller module via the x-seller.* prefix. --}}
        <x-seller.sidebar />

        {{-- ============================ MAIN ============================= --}}
        <div class="seller-main">
            @yield('content')
        </div>
    </div>

    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <script>
        lucide.createIcons();
    </script>
    @stack('scripts')
</body>
</html>
