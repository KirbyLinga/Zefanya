<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>@yield('title', 'Shipment details')</title><x-fonts />@vite(['resources/css/app.css']) @stack('styles')</head>
<body class="min-h-screen bg-primary-50"><main class="min-w-0 p-4 sm:p-5">@yield('content')</main><script src="{{ asset('js/lucide.min.js') }}"></script><script>lucide.createIcons();</script>@stack('scripts')</body></html>
