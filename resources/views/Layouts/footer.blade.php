<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title', 'Zefanya — Elevated Shopping, Curated Beautifully')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;800&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">
    @vite(['resources/css/design-system.css', 'resources/css/landing.css', 'resources/css/login-modal.css'])
    @stack('styles')
</head>
<body>
<div class="html-body">

    {{-- ===== Top navbar (shared) ===== --}}
    @include('Components.navbar')

    {{-- ===== Hero (full-bleed, outside .main) ===== --}}
    @yield('hero')

    {{-- ===== Page content ===== --}}
    <div class="main">
        @yield('content')
    </div>

    {{-- ===== Footer (shared) ===== --}}
    <footer class="site-footer">
        <div class="site-footer__inner">
            <div class="site-footer__grid">
                <div class="footer-col">
                    <h4 class="footer-col__title">Customer Service</h4>
                    <ul class="footer-col__links">
                        <li><a href="#">Help Center</a></li>
                        <li><a href="#">How to Buy</a></li>
                        <li><a href="#">Return &amp; Refund</a></li>
                        <li><a href="#">Contact Us</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4 class="footer-col__title">About Zefanya</h4>
                    <ul class="footer-col__links">
                        <li><a href="#">About Us</a></li>
                        <li><a href="#">Careers</a></li>
                        <li><a href="#">Privacy Policy</a></li>
                        <li><a href="#">Terms of Service</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4 class="footer-col__title">Join Us</h4>
                    <ul class="footer-col__links">
                        <li><a href="#">Seller Center</a></li>
                        <li><a href="#">Become a Courier</a></li>
                        <li><a href="#">Affiliate Program</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4 class="footer-col__title">Download App</h4>
                    <div class="footer-app-buttons">
                        <button class="footer-app-btn" type="button">
                            <i data-lucide="apple" width="16" height="16"></i>
                            <span>APP STORE</span>
                        </button>
                        <button class="footer-app-btn" type="button">
                            <i data-lucide="play" width="16" height="16"></i>
                            <span>GOOGLE PLAY</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="site-footer__bottom">
                <p class="site-footer__copyright">&copy; {{ date('Y') }} ZEFANYA. ALL RIGHTS RESERVED.</p>
                <div class="site-footer__social">
                    <a href="#" aria-label="Email"><i data-lucide="at-sign" width="20" height="20"></i></a>
                    <a href="#" aria-label="Website"><i data-lucide="globe" width="20" height="20"></i></a>
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
