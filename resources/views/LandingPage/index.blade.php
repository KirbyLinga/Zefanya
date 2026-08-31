@extends('Layouts.footer')

@section('title', 'Zefanya')

@section('hero')
    {{-- ===== Hero ===== --}}
    <div class="hero-section">
        <div class="mega-banner" id="heroBanner" style="background-image: url('{{ asset('Images/HeroBG.jpg') }}')">
            <div class="mega-overlay"></div>

            <div class="mega-content">
                <p class="mega-eyebrow">New Arrivals Weekly</p>
                <h1 class="mega-title">Everything you need, all in one place</h1>
                <p class="mega-subtitle"> From daily essentials to unexpected finds, delivered straight to your door.</p>
                <button class="mega-cta" type="button">SHOP NOW</button>
            </div>
        </div>
    </div>
@endsection

@section('content')
    {{-- ===== Shop by Categories ===== --}}
    <div class="section-categories">
        <div class="container-5">
            <div class="heading-2">
                <div class="text-10">Explore Categories</div>
            </div>
            <div class="link-2">
                <div class="text">VIEW ALL</div>
                <div class="div-2">
                    <i data-lucide="arrow-right" width="12" height="12" style="color: #79545c;"></i>
                </div>
            </div>
        </div>

        <div class="cat-carousel-wrap">
            <div class="cat-carousel" id="catCarousel">
                <div class="cat-track" id="catTrack">
            @foreach([
                ['icon' => 'book-open',    'label' => 'Books and Media'],
                ['icon' => 'heart-pulse',  'label' => 'Health and Beauty'],
                ['icon' => 'mountain',     'label' => 'Sports and Outdoors'],
                ['icon' => 'flower-2',     'label' => 'Home and Garden'],
                ['icon' => 'baby',         'label' => 'Kids and Baby'],
                ['icon' => 'shirt',        'label' => "Men's Apparel"],
                ['icon' => 'sparkles',     'label' => "Women's Apparel"],
                ['icon' => 'smartphone',   'label' => 'Electronics and Gadgets'],
                ['icon' => 'paw-print',    'label' => 'Pet Supplies'],
                ['icon' => 'utensils',     'label' => 'Food and Gourmet'],
                ['icon' => 'car',          'label' => 'Automotive & Motorcycle'],
                ['icon' => 'sofa',         'label' => 'Furniture and Office Equipment'],
                ['icon' => 'gem',          'label' => 'Jewelry and Watches'],
                ['icon' => 'pencil-ruler', 'label' => 'Office and School Supplies'],
            ] as $cat)
                <div class="div-4">
                    <div class="img-wrapper">
                        <i data-lucide="{{ $cat['icon'] }}" width="26" height="26" style="color: #79545c;"></i>
                    </div>
                    <div class="text-12">{{ $cat['label'] }}</div>
                </div>
            @endforeach
                </div>
            </div>
        </div>

        <div class="cat-controls">
            <button type="button" class="cat-nav cat-nav--left" id="catPrev" aria-label="Previous categories">
                <i data-lucide="chevron-left" width="18" height="18" style="color: #79545c;"></i>
            </button>

            <div class="cat-dots" id="catDots" role="tablist" aria-label="Category pages"></div>

            <button type="button" class="cat-nav cat-nav--right" id="catNext" aria-label="Next categories">
                <i data-lucide="chevron-right" width="18" height="18" style="color: #79545c;"></i>
            </button>
        </div>
    </div>

    @push('scripts')
    <script>
        (function () {
            var carousel = document.getElementById('catCarousel');
            var track = document.getElementById('catTrack');
            var dotsWrap = document.getElementById('catDots');
            if (!carousel || !track || !dotsWrap) return;

            var page = 0;

            function perView() {
                var v = getComputedStyle(carousel).getPropertyValue('--cat-per-view');
                var n = parseInt(v, 10);
                return n > 0 ? n : 5;
            }

            function pageCount() {
                return Math.ceil(track.children.length / perView());
            }

            function go(idx) {
                var pages = pageCount();
                page = Math.max(0, Math.min(idx, pages - 1));

                var card = track.children[0];
                if (!card) return;
                var cardW = card.getBoundingClientRect().width;
                var gap = parseFloat(getComputedStyle(track).gap) || 0;
                var step = (cardW + gap) * perView();
                var maxShift = Math.max(0, track.scrollWidth - carousel.clientWidth);
                var shift = Math.min(page * step, maxShift);

                track.style.transform = 'translateX(-' + shift + 'px)';

                Array.prototype.forEach.call(dotsWrap.children, function (d, i) {
                    d.classList.toggle('active', i === page);
                });
            }

            function setup() {
                var pages = pageCount();
                if (page >= pages) page = pages - 1;

                dotsWrap.innerHTML = '';
                for (var i = 0; i < pages; i++) {
                    (function (idx) {
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'cat-dot' + (idx === page ? ' active' : '');
                        b.setAttribute('aria-label', 'Go to category page ' + (idx + 1));
                        b.addEventListener('click', function () { go(idx); });
                        dotsWrap.appendChild(b);
                    })(i);
                }
                go(page);
            }

            function prev() {
                go(page === 0 ? pageCount() - 1 : page - 1);
            }

            function next() {
                go(page === pageCount() - 1 ? 0 : page + 1);
            }

            var prevBtn = document.getElementById('catPrev');
            var nextBtn = document.getElementById('catNext');
            if (prevBtn) prevBtn.addEventListener('click', prev);
            if (nextBtn) nextBtn.addEventListener('click', next);

            var t;
            window.addEventListener('resize', function () {
                clearTimeout(t);
                t = setTimeout(setup, 150);
            });

            setup();
        })();
    </script>
    @endpush

    {{-- ===== Feautred-Product-Recommended ===== --}}
    <div class="section-recommended">
        <div class="heading-3">
            <div class="text-wrapper-2">Recommended for You</div>
        </div>

        <div class="container-7">
            @include('Components.product-card', [
                'image' => 'ab6axudtt6jbu5-nkg5wzbbijzeszxns-qve3vvsrvsfsiw5hnpqdw9bs5uc3ureqby0mi-jcbaexaapmkcbyjoxrjelhugy8rubofhpu-toqo7jwwijpb9gq3oqc-rue8evuu7qjgo-l3bf2mwwehrohoven6n6s-0hcsfv2p-2txan8lnfkwv-taiztklfo3ezfq0wmezpqyp2wvwrv4jhx-0lvyb2a34vdoa1ikcm7wzd5sykc4mqtsp5.png',
                'title' => 'Wireless Noise Cancelling<br/>Headphones',
                'price' => '$129.99',
                'oldPrice' => '$199.99',
                'badge' => 'SALE',
            ])
            @include('Components.product-card', [
                'image' => 'ab6axudxnss12ck0hhlnxkyy7qzfuqwpffzkvmv1gwyxm0fjcl5ah7vreiahqaxofwbaypzl-infc79pbltoj31v7twsrs6cyldsjneridoj8wxnrdkrdgha4optxzh1-veeqmic-xj6duq9izv6w0w36tsphgsbwfwgeboh2rgw-ihaiycpodu35iutifcnhqj8mniw9mcq9kwdswcn100zcjxb6lpmxsaq3klplnwdwc-x6vzw5rgi0dl.png',
                'title' => 'Minimalist Ceramic Coffee<br/>Mug Set',
                'price' => '$24.50',
            ])
            @include('Components.product-card', [
                'image' => 'ab6axucbufyg85myhfn0sumboaoq0ns4gmxefybuwbhbagaxf2rdoz-67booqib4fmnqi2gylmxigy8phqjw3qbu5scm-qq2jzopjwkq3caydvxzfdrwdfnjgysl0azk6glspkium8qfjogpp2oxr91hjc-zpbp7wcbvdikvvkd8wrikomr4myi-q28ap5zxaphi-iru-pxcuhgq8qub1uxpqjnski-voiusqt5chmhjzmom5zwsnfkec9eh.png',
                'title' => "Men's Classic Cotton<br/>Crewneck",
                'price' => '$32.00',
                'badge' => 'NEW',
                'badgeStyle' => 'new',
            ])
            @include('Components.product-card', [
                'image' => 'ab6axuaqrpvz4obmcgbskrmz4ewsw7tfxn9kr5w0jihp8eck-ey7pyflbluhfvfr0uvzf5chjmwppioxtzgaqkpn0-wumted-f2gu-zizw8row4eajwkslaj0kjcdiuvsto6y0m0r6gmiz9teqz6gvhxlhxkr5dov0dsl4hxxjngzlrtfurxieiqf5tp4ol2bgbw2nn1t49d-jqvye7xivsmvabg0my2novxotfdw8u5ssuoo2ibhc8gnwnx.png',
                'title' => 'Smart Fitness Tracker<br/>Watch',
                'price' => '$185.00',
            ])
        </div>

        <div class="container-10">
            <button class="button-8"><div class="text-21">LOAD MORE</div></button>
        </div>
    </div>

@endsection
