<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title', 'Zefanya — Elevated Shopping, Curated Beautifully')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;800&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/login-modal.css'])
    @stack('styles')
</head>
<body>
<div class="html-body min-w-0 overflow-x-hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">

    {{-- ===== Top navbar (shared) =====
         Landing/public shell: always show the public-facing nav (Login / Register)
         regardless of any session state. Authenticated buyers have their own
         navbar inside the Buyer layout (Buyer/Layouts/app.blade.php) where
         variant='buyer' is correct. Using 'landing' here means the marketing
         pages never expose a logged-in account dropdown.
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
                        <li><a href="#" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Help Center</a></li>
                        <li><a href="#" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">How to Buy</a></li>
                        <li><a href="#" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Return &amp; Refund</a></li>
                        <li><a href="#" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Contact Us</a></li>
                    </ul>
                </div>

                <div class="flex min-w-0 flex-col gap-5">
                    <h4 class="m-0 font-sans text-xs font-semibold uppercase leading-[14.4px] tracking-[1.2px] text-neutral-950">About Zefanya</h4>
                    <ul class="m-0 flex list-none flex-col gap-3 p-0">
                        <li><a href="#" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">About Us</a></li>
                        <li><a href="#" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Careers</a></li>
                        <li><a href="#" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Privacy Policy</a></li>
                        <li><a href="#" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Terms of Service</a></li>
                    </ul>
                </div>

                <div class="flex min-w-0 flex-col gap-5">
                    <h4 class="m-0 font-sans text-xs font-semibold uppercase leading-[14.4px] tracking-[1.2px] text-neutral-950">Join Us</h4>
                    <ul class="m-0 flex list-none flex-col gap-3 p-0">
                        <li><a href="#" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Seller Center</a></li>
                        <li><a href="#" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Become a Courier</a></li>
                        <li><a href="#" class="font-sans text-[15px] leading-[22px] text-muted-ink transition-colors duration-150 hover:text-primary-800">Affiliate Program</a></li>
                    </ul>
                </div>

                <div class="flex min-w-0 flex-col gap-5">
                    <h4 class="m-0 font-sans text-xs font-semibold uppercase leading-[14.4px] tracking-[1.2px] text-neutral-950">Download App</h4>
                    <div class="flex w-full max-w-[252px] flex-col gap-3">
                        <button class="flex h-11 cursor-pointer items-center justify-center gap-2.5 whitespace-nowrap rounded-sm border border-line bg-cream px-4 font-sans text-xs font-semibold leading-[14.4px] tracking-[1.2px] text-neutral-950 transition-colors duration-200 hover:bg-white [&_svg]:shrink-0" type="button">
                            <i data-lucide="apple" width="16" height="16"></i>
                            <span>APP STORE</span>
                        </button>
                        <button class="flex h-11 cursor-pointer items-center justify-center gap-2.5 whitespace-nowrap rounded-sm border border-line bg-cream px-4 font-sans text-xs font-semibold leading-[14.4px] tracking-[1.2px] text-neutral-950 transition-colors duration-200 hover:bg-white [&_svg]:shrink-0" type="button">
                            <i data-lucide="play" width="16" height="16"></i>
                            <span>GOOGLE PLAY</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between gap-4 border-t border-line pt-6 max-sm:flex-col max-sm:items-start max-sm:gap-4">
                <p class="m-0 font-sans text-xs font-semibold leading-[14.4px] tracking-[1.2px] text-muted-ink">&copy; {{ date('Y') }} ZEFANYA. ALL RIGHTS RESERVED.</p>
                <div class="flex items-center gap-4">
                    <a href="#" aria-label="Email" class="inline-flex items-center text-neutral-950 transition-colors duration-150 hover:text-primary-800"><i data-lucide="at-sign" width="20" height="20"></i></a>
                    <a href="#" aria-label="Website" class="inline-flex items-center text-neutral-950 transition-colors duration-150 hover:text-primary-800"><i data-lucide="globe" width="20" height="20"></i></a>
                </div>
            </div>
        </div>
    </footer>

    @include('Components.login-modal')
</div>

<script src="{{ asset('js/lucide.min.js') }}"></script>
<script>lucide.createIcons();</script>
@stack('scripts')
</body>
</html>