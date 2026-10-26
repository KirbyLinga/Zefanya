<!DOCTYPE html>
<html lang="en" data-theme-key="public.theme">
<head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title', 'Zefanya — Elevated Shopping, Curated Beautifully')</title>

    {{-- Theme init MUST run before the stylesheet paints: reads the PUBLIC
         (landing/auth) theme — localStorage 'public.theme', the key declared
         once on the <html> tag above — and sets data-theme on <html>. The
         role panels each use their own key; 'zf-theme' is only the pre-split
         shared key, read as a one-time fallback. Default: light. --}}
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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;800&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/login-modal.css'])
    @stack('styles')
</head>
<body>
<div class="html-body min-w-0 overflow-x-hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">

    {{-- ===== Top navbar (shared) =====
         Landing/public shell: PUBLIC ONLY — always variant 'landing', so every
         visitor gets the public navbar (Login / Register / cart / wishlist /
         theme toggle). The '/' route redirects a signed-in buyer to buyer.home,
         where Buyer/Layouts/app.blade.php renders variant 'buyer' with the
         account dropdown.
         There is NO sidebar in the public/buyer shells — navigation is the top
         navbar only. Do not add one. --}}
    @include('Components.navbar', ['variant' => 'landing'])

    {{-- ===== Hero (full-bleed, outside .main) ===== --}}
    @yield('hero')

    {{-- ===== Page content ===== --}}
    <div class="relative mx-auto mt-0.5 flex w-full max-w-[1440px] flex-1 flex-col items-start px-16 max-lg:px-8 max-sm:px-4">
        @yield('content')
    </div>

    {{-- ===== Footer (shared) ===== --}}
    <footer class="mt-16 w-full bg-secondary-300 lg:mt-[100px]">
        <div class="mx-auto flex max-w-[1440px] flex-col gap-16 px-16 pb-12 pt-24 max-lg:gap-12 max-lg:px-8 max-lg:pt-20 max-sm:px-4 max-sm:pb-10 max-sm:pt-16">
            <div class="grid grid-cols-4 gap-8 max-lg:grid-cols-2 max-sm:grid-cols-1">
                <div class="flex min-w-0 flex-col gap-5">
                    <h4 class="m-0 font-sans text-xs font-semibold uppercase leading-[14.4px] tracking-[1.2px] text-neutral-950">Customer Service</h4>
                    <ul class="m-0 flex list-none flex-col gap-3 p-0">
                        <li><a href="{{ route('buyer.help.index') }}" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Help Center</a></li>
                        <li><a href="{{ route('buyer.help.index') }}" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">How to Buy</a></li>
                        <li><a href="{{ route('buyer.pages.shipping-returns') }}" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Return &amp; Refund</a></li>
                        <li><a href="{{ route('buyer.pages.contact') }}" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Contact Us</a></li>
                    </ul>
                </div>

                <div class="flex min-w-0 flex-col gap-5">
                    <h4 class="m-0 font-sans text-xs font-semibold uppercase leading-[14.4px] tracking-[1.2px] text-neutral-950">About Zefanya</h4>
                    <ul class="m-0 flex list-none flex-col gap-3 p-0">
                        <li><a href="{{ route('buyer.pages.about') }}" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">About Us</a></li>
                        <li><a href="{{ route('buyer.pages.privacy-policy') }}" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Privacy Policy</a></li>
                        <li><a href="{{ route('buyer.pages.terms-of-service') }}" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Terms of Service</a></li>
                    </ul>
                </div>

                <div class="flex min-w-0 flex-col gap-5">
                    <h4 class="m-0 font-sans text-xs font-semibold uppercase leading-[14.4px] tracking-[1.2px] text-neutral-950">Join Us</h4>
                    <ul class="m-0 flex list-none flex-col gap-3 p-0">
                        <li><a href="{{ route('register.seller') }}" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Seller Center</a></li>
                        <li><a href="{{ route('register.logistics') }}" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Become a Courier</a></li>
                    </ul>
                </div>

                <div class="flex min-w-0 flex-col gap-5">
                    <h4 class="m-0 font-sans text-xs font-semibold uppercase leading-[14.4px] tracking-[1.2px] text-neutral-950">Download App</h4>
                    <div class="flex w-full max-w-[252px] flex-col gap-3">
                        <div class="flex min-h-11 items-center gap-2.5 rounded-sm border border-line bg-cream px-4 py-3 font-sans text-xs text-neutral-950">
                            <i data-lucide="apple" width="16" height="16"></i>
                            <span>APP STORE - Coming soon</span>
                        </div>
                        <div class="flex min-h-11 items-center gap-2.5 rounded-sm border border-line bg-cream px-4 py-3 font-sans text-xs text-neutral-950">
                            <i data-lucide="play" width="16" height="16"></i>
                            <span>GOOGLE PLAY - Coming soon</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between gap-4 border-t border-line pt-6 max-sm:flex-col max-sm:items-start max-sm:gap-4">
                <p class="m-0 font-sans text-xs font-semibold leading-[14.4px] tracking-[1.2px] text-muted-ink">&copy; {{ date('Y') }} ZEFANYA. ALL RIGHTS RESERVED.</p>
                <div class="flex items-center gap-4">
                    <a href="mailto:support@zefanya.shop" aria-label="Email" class="inline-flex items-center text-neutral-950 transition-colors duration-150 hover:text-primary-800"><i data-lucide="at-sign" width="20" height="20"></i></a>
                    <a href="{{ route('home') }}" aria-label="Website" class="inline-flex items-center text-neutral-950 transition-colors duration-150 hover:text-primary-800"><i data-lucide="globe" width="20" height="20"></i></a>
                </div>
            </div>
        </div>
    </footer>

  <div class="pointer-events-none fixed bottom-4 right-4 z-[80] w-80 max-w-[calc(100vw-2rem)] translate-y-10 opacity-0 transition-all duration-200 [&.show]:translate-y-0 [&.show]:opacity-100 [&.show]:pointer-events-auto" id="toast" role="status" aria-live="polite">
    <div class="rounded-lg border border-buyer-line bg-white p-4 shadow-lg">
      <div class="mb-3 flex items-start gap-2">
        <i class="fa-solid fa-circle-check mt-0.5 flex-shrink-0 text-green-500"></i>
        <p class="text-sm font-semibold text-buyer-ink">Added to cart</p>
      </div>
      <div class="mb-3 border-b border-buyer-line pb-3">
        <p class="text-sm font-semibold text-buyer-ink" id="toastProductName">Product</p>
        <p class="mt-1 text-xs text-buyer-ink-soft" id="toastProductPrice">Price</p>
      </div>
      <button type="button" id="toastContinueBtn" class="w-full rounded border border-buyer-ink px-3 py-2 text-xs font-semibold text-buyer-ink transition-colors hover:bg-buyer-ink hover:text-white">
        Continue Shopping
      </button>
    </div>
  </div>


    @include('Components.login-modal')
</div>

<script src="{{ asset('js/lucide.min.js') }}"></script>
<script>lucide.createIcons();</script>

{{-- Light/dark toggle behaviour — the same file the seller/logistics panels load
     (localStorage 'zf-theme'; the navbar button carries [data-theme-toggle]). --}}
@vite(['resources/js/seller/theme-toggle.js', 'resources/js/buyer/buyer.js', 'resources/js/buyer/add-to-cart-animation.js', 'resources/js/buyer/wishlist-cards.js'])

@include('Components.buyer.wishlist-config')
@stack('scripts')
</body>
</html>