@extends('Layouts.footer')

@section('title', 'Zefanya')

@section('hero')
    {{-- ===== Hero =====
         Note: ≤1024px headline is 46px (was set by _products.scss's media
         query, which won the cascade over _hero.scss's 40px). Replicated as
         max-lg:text-[46px] to stay pixel-identical. --}}
    <header class="block">
        <div class="relative flex w-full min-h-[600px] items-center justify-center overflow-hidden bg-cover bg-center bg-no-repeat pt-20 pb-[120px] max-lg:min-h-[480px] max-lg:pt-14 max-lg:pb-12 max-sm:min-h-[420px] max-sm:pt-9 max-sm:pb-7" id="heroBanner" style="background-image: url('{{ asset('Images/HeroBG.jpg') }}')">
            <div class="absolute inset-0 bg-[linear-gradient(180deg,rgba(121,84,92,0.5)_0%,rgba(121,84,92,0.34)_45%,rgba(72,50,55,0.62)_100%)] backdrop-blur-[2px]"></div>

            <div class="relative z-[1] mx-auto flex w-full max-w-[760px] flex-col items-center justify-center px-6 text-center text-white max-sm:px-4">
                <p class="mb-[26px] inline-flex items-center rounded-full border border-[rgba(255,248,247,0.35)] bg-[rgba(255,248,247,0.16)] px-[18px] py-[9px] font-sans text-xs font-semibold uppercase tracking-[2.4px] text-cream backdrop-blur-[2px] max-sm:px-3.5 max-sm:py-[7px] max-sm:text-[11px]">New Arrivals Weekly</p>
                <h1 class="m-0 font-serif font-normal text-[60px] leading-[1.12] text-white [text-wrap:balance] max-lg:text-[46px] max-sm:text-[30px]">Everything you need, all in one place</h1>
                <p class="mx-auto mb-8 mt-[18px] max-w-[560px] font-sans text-lg leading-[1.6] text-[rgba(255,248,247,0.92)] max-lg:text-base"> From daily essentials to unexpected finds, delivered straight to your door.</p>
                <button class="inline-flex cursor-pointer items-center justify-center rounded bg-neutral-950 px-[30px] py-[15px] text-sm font-semibold tracking-[1.4px] text-white transition-[background-color,color,box-shadow,transform] duration-200 hover:-translate-y-0.5 hover:bg-primary-900 hover:shadow-[0_12px_24px_rgba(30,27,27,0.3)] max-sm:w-full" type="button">SHOP NOW</button>
            </div>
        </div>
    </header>
@endsection

@section('content')
    {{-- ===== Shop by Categories =====
         IMPORTANT: --cat-per-view is a REAL CSS custom property, set via
         Tailwind arbitrary-property classes on #catCarousel (base 5,
         ≤1024px → 4, ≤640px → 2). The carousel script below reads it with
         getComputedStyle() and the card flex-basis calc() consumes it.
         Do NOT replace it with static utilities or an inline style (an
         inline style would beat the responsive variants and break the
         carousel page math). --}}
    <section class="relative flex w-full flex-col items-start gap-8 self-stretch pt-[50px]">
        <div class="relative flex w-full items-end justify-between self-stretch">
            <div class="flex items-center whitespace-nowrap font-serif text-[clamp(30px,4vw,48px)] font-normal leading-[1.2] text-neutral-950">Explore Categories</div>
            <div class="inline-flex cursor-pointer items-center gap-1.5 font-sans text-[13px] font-semibold uppercase tracking-[1.6px] text-primary-800 transition-colors duration-200 hover:text-primary-900">
                <div>VIEW ALL</div>
                <div class="inline-flex items-center">
                    <i data-lucide="arrow-right" width="12" height="12" style="color: #79545c;"></i>
                </div>
            </div>
        </div>

        <div class="relative w-full">
            <div class="[--cat-per-view:5] relative w-full overflow-hidden max-lg:[--cat-per-view:4] max-sm:[--cat-per-view:2]" id="catCarousel">
                <div class="flex items-stretch gap-4 transition-transform duration-[450ms] will-change-transform" id="catTrack">
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
                ['icon' => 'gem',          'label' => 'Jewelry   and Watches'],
                ['icon' => 'pencil-ruler', 'label' => 'Office and School Supplies'],
            ] as $cat)
                <div class="group basis-[calc((100%_-_((var(--cat-per-view)_-_1)_*_16px))_/_var(--cat-per-view))] relative z-auto flex min-h-[120px] min-w-[120px] shrink-0 flex-col items-center justify-center rounded-lg border border-line bg-blush px-4 py-6 transition-[flex,padding,margin,box-shadow,border-color] duration-300 hover:z-[1] hover:grow-[0.3] hover:-mx-1 hover:px-5 hover:py-8 hover:border-primary-800 hover:shadow-[0_12px_32px_rgba(30,27,27,0.18)] hover:basis-[calc((100%_-_((var(--cat-per-view)_-_1)_*_16px))_/_var(--cat-per-view)_*_1.3)]">
                    <div class="mb-2 inline-flex h-16 w-16 items-center justify-center rounded-full bg-secondary-300 transition-transform duration-300 group-hover:scale-[1.2]">
                        <i data-lucide="{{ $cat['icon'] }}" width="26" height="26" style="color: #79545c;"></i>
                    </div>
                    <div class="mt-2 flex w-full items-center justify-center whitespace-normal text-center font-sans text-xs font-semibold uppercase leading-[1.5] tracking-[1.2px] text-neutral-950 transition-[font-size,margin-top] duration-300 group-hover:mt-3 group-hover:text-sm">{{ $cat['label'] }}</div>
                </div>
            @endforeach
                </div>
            </div>
        </div>

        <div class="mt-7 flex w-full items-center justify-center gap-[18px] self-stretch">
            <button type="button" class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full border border-line bg-cream p-0 shadow-[0_2px_8px_rgba(41,38,38,0.1)] transition-colors duration-200 hover:bg-secondary-300 hover:shadow-[0_4px_12px_rgba(41,38,38,0.15)]" id="catPrev" aria-label="Previous categories">
                <i data-lucide="chevron-left" width="18" height="18" style="color: #79545c;"></i>
            </button>

            <div class="flex items-center justify-center gap-2.5" id="catDots" role="tablist" aria-label="Category pages"></div>

            <button type="button" class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full border border-line bg-cream p-0 shadow-[0_2px_8px_rgba(41,38,38,0.1)] transition-colors duration-200 hover:bg-secondary-300 hover:shadow-[0_4px_12px_rgba(41,38,38,0.15)]" id="catNext" aria-label="Next categories">
                <i data-lucide="chevron-right" width="18" height="18" style="color: #79545c;"></i>
            </button>
        </div>
    </section>

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
                    // Dots carry full Tailwind utility classes (no .active
                    // state rule exists anymore) — toggle both utilities.
                    d.classList.toggle('w-[22px]', i === page);
                    d.classList.toggle('bg-primary-800', i === page);
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
                        // Full Tailwind utilities instead of the old
                        // .cat-dot / .cat-dot.active CSS classes.
                        b.className = 'w-2 h-2 cursor-pointer rounded-full border-0 bg-line p-0 transition-[background-color,width] duration-300'
                            + (idx === page ? ' w-[22px] bg-primary-800' : '');
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

    {{-- ===== Featured Products (Recommended) ===== --}}
    <section class="relative mt-24 flex w-full flex-col items-start gap-10 max-lg:gap-8">
        <div class="flex items-end">
            <div class="font-serif text-[clamp(30px,4vw,48px)] font-normal leading-[1.2] text-neutral-950">Recommended for You</div>
        </div>

        <div class="grid w-full grid-cols-4 gap-7 max-lg:grid-cols-2 max-lg:gap-6 max-sm:grid-cols-2 max-sm:gap-3.5">
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

        <div class="flex w-full justify-center">
            <button class="inline-flex cursor-pointer items-center justify-center rounded border border-neutral-950 bg-transparent px-10 py-3.5 text-[13px] font-semibold tracking-[1.6px] text-neutral-950 transition-colors duration-200 hover:bg-neutral-950 hover:text-white"><div>LOAD MORE</div></button>
        </div>
    </section>

@endsection
