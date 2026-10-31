@extends('Buyer.Layouts.app')
@section('title', e($seller->business_name))
@section('content')
@php
    $logoUrl = $seller->store_logo_path ? Storage::disk('public')->url($seller->store_logo_path) : null;
    $bannerUrl = $seller->store_banner_path ? Storage::disk('public')->url($seller->store_banner_path) : null;
    $location = collect([$seller->municipality_name, $seller->province_name])->filter()->implode(', ');
    $rating = $seller->reviews_count ? (float) $seller->reviews_avg_rating : null;
    $ratingLabel = $rating !== null ? number_format($rating, 1) : null;
    $fullStars = $rating !== null ? (int) floor($rating) : 0;
    $hasHalf = $rating !== null && ($rating - $fullStars) >= 0.5;
    $activeLabel = $uncategorized ? 'Uncategorized' : ($selectedCategory?->name ?? 'All products');
    $allCategories = $storeCategories->flatMap(fn ($category) => collect([$category])->merge($category->children));
    $tabQuery = array_filter([
        'category' => $uncategorized ? 'uncategorized' : $selectedCategory?->id,
        'q' => $search !== '' ? $search : null,
        'sort' => $sort !== 'relevance' ? $sort : null,
    ], fn ($value) => $value !== null && $value !== '');
    $tabUrl = fn (string $target) => route('buyer.stores.show', array_merge(['seller' => $seller, 'tab' => $target], $tabQuery));
    $categoryQuery = array_filter([
        'q' => $search !== '' ? $search : null,
        'sort' => $sort !== 'relevance' ? $sort : null,
    ], fn ($value) => $value !== null && $value !== '');
@endphp

<div class="min-h-screen bg-gradient-to-b from-neutral-50 to-primary-50/70">
    <div class="mx-auto max-w-[1440px] px-4 py-6 sm:px-8 lg:px-12">

        {{-- Breadcrumb --}}
        <nav aria-label="Breadcrumb" class="mb-4 flex flex-wrap items-center gap-2 text-[12px] text-buyer-ink-soft">
            <a href="{{ route('buyer.home') }}" class="transition-colors hover:text-buyer-ink">Home</a>
            <i data-lucide="chevron-right" width="12" height="12"></i>
            <a href="{{ route('buyer.products.index') }}" class="transition-colors hover:text-buyer-ink">Shop</a>
            <i data-lucide="chevron-right" width="12" height="12"></i>
            <span class="truncate font-semibold text-buyer-ink">{{ $seller->business_name }}</span>
        </nav>

        {{-- Store header --}}
        <header class="overflow-hidden rounded-3xl border border-buyer-line bg-white shadow-sm">
            <div class="relative h-36 bg-buyer-ink sm:h-52">
                @if ($bannerUrl)
                    <img src="{{ $bannerUrl }}" alt="{{ $seller->business_name }} store banner" class="absolute inset-0 h-full w-full object-cover">
                @else
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(255,255,255,0.18),transparent_45%),radial-gradient(circle_at_80%_0%,rgba(224,169,61,0.22),transparent_40%)]"></div>
                @endif
                <div class="absolute inset-0 bg-gradient-to-t from-black/45 via-black/10 to-transparent"></div>
            </div>

            <div class="relative px-5 pb-6 sm:px-8">
                <div class="flex flex-col gap-4 sm:gap-5 lg:flex-row lg:items-start">
                    {{-- Logo --}}
                    <div class="relative -mt-12 flex size-24 shrink-0 self-start items-center justify-center overflow-hidden rounded-full border-4 border-white bg-white shadow-lg sm:-mt-14 sm:size-28">
                        @if ($logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ $seller->business_name }} store logo" class="h-full w-full rounded-full object-cover">
                        @else
                            <div class="flex h-full w-full items-center justify-center rounded-full bg-buyer-ink font-serif text-3xl font-bold text-white" aria-label="Store initials">
                                {{ $seller->storeInitials() }}
                            </div>
                        @endif
                    </div>

                    {{-- Identity --}}
                    <div class="min-w-0 flex-1 pb-1 lg:pt-5">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="break-words font-serif text-2xl font-semibold text-buyer-ink sm:text-3xl">{{ $seller->business_name }}</h1>
                            <span class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2.5 py-1 text-[11px] font-semibold text-primary-800">
                                <i data-lucide="badge-check" width="13" height="13"></i>
                                Verified Store
                            </span>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-buyer-ink-soft">
                            @if ($location)
                                <span class="inline-flex items-center gap-1.5">
                                    <i data-lucide="map-pin" width="14" height="14"></i>
                                    Ships from {{ $location }}
                                </span>
                            @endif
                            <span class="inline-flex items-center gap-1.5">
                                <i data-lucide="package-check" width="14" height="14"></i>
                                {{ number_format($itemsSold) }} items sold
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <i data-lucide="calendar-days" width="14" height="14"></i>
                                Joined {{ $seller->created_at?->format('M Y') ?? '—' }}
                            </span>
                        </div>

                        @if (filled($seller->store_description))
                            <p class="mt-3 max-w-3xl whitespace-pre-line break-words text-sm leading-relaxed text-buyer-ink-soft">{{ trim($seller->store_description) }}</p>
                        @endif
                    </div>

                    {{-- Actions --}}
                    <div class="flex shrink-0 flex-col items-start gap-2 pb-1 lg:items-end lg:pt-5">
                        <div class="flex items-center gap-2">
                            @auth('buyer')
                            <form method="POST" action="{{ route('buyer.stores.follow', $seller) }}">
                                @csrf
                            <button type="submit" aria-pressed="{{ $isFollowing ? 'true' : 'false' }}"
                                    class="inline-flex min-h-10 items-center gap-2 rounded-full bg-buyer-ink px-5 py-2 text-[13px] font-semibold text-white transition-colors hover:bg-buyer-ink/90">
                                <i data-lucide="user-plus" width="16" height="16"></i>
                                {{ $isFollowing ? 'Unfollow' : 'Follow' }}
                            </button>
                            </form>
                            @else
                            <button type="button" onclick="window.openLoginModal?.()" title="Log in to follow this store" class="inline-flex min-h-10 items-center gap-2 rounded-full bg-buyer-ink px-5 py-2 text-[13px] font-semibold text-white">Follow</button>
                            @endauth
                            <button type="button"
                                    data-store-share
                                    data-share-url="{{ route('buyer.stores.show', $seller) }}"
                                    data-share-title="{{ $seller->business_name }}"
                                    class="inline-flex min-h-10 items-center gap-2 rounded-full border border-buyer-line bg-white px-5 py-2 text-[13px] font-semibold text-buyer-ink transition-colors hover:border-buyer-ink/30 hover:bg-neutral-50">
                                <i data-lucide="share-2" width="16" height="16"></i>
                                Share
                            </button>
                        </div>
                        <span data-store-share-status role="status" aria-live="polite" class="text-[11px] text-buyer-ink-soft"></span>
                    </div>
                </div>

                {{-- Store stats --}}
                <dl class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-buyer-line bg-buyer-line sm:grid-cols-5">
                    <div class="bg-white px-4 py-4 text-center">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">Store Rating</dt>
                        <dd class="mt-1 flex items-center justify-center gap-1.5">
                            @if ($ratingLabel)
                                <span class="font-serif text-xl font-semibold text-buyer-ink">{{ $ratingLabel }}</span>
                                <span class="flex items-center gap-0.5" aria-hidden="true">
                                    @for ($star = 1; $star <= 5; $star++)
                                        @if ($star <= $fullStars)
                                            <i class="fa-solid fa-star text-[10px] text-buyer-star"></i>
                                        @elseif ($star === $fullStars + 1 && $hasHalf)
                                            <i class="fa-solid fa-star-half-stroke text-[10px] text-buyer-star"></i>
                                        @else
                                            <i class="fa-regular fa-star text-[10px] text-neutral-300"></i>
                                        @endif
                                    @endfor
                                </span>
                            @else
                                <span class="font-serif text-xl font-semibold text-buyer-ink">—</span>
                            @endif
                        </dd>
                    </div>
                    <div class="bg-white px-4 py-4 text-center">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">Total Products</dt>
                        <dd class="mt-1 font-serif text-xl font-semibold text-buyer-ink">{{ number_format($activeProductsCount) }}</dd>
                    </div>
                    <div class="bg-white px-4 py-4 text-center">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">Followers</dt>
                        <dd class="mt-1 font-serif text-xl font-semibold text-buyer-ink" data-followers-count>{{ number_format($followersCount) }}</dd>
                    </div>
                    <div class="bg-white px-4 py-4 text-center">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">Response Rate</dt>
                        <dd class="mt-1 font-serif text-xl font-semibold text-buyer-ink" title="Messaging analytics are not available yet">—</dd>
                    </div>
                    <div class="col-span-2 bg-white px-4 py-4 text-center sm:col-span-1">
                        <dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">Joined</dt>
                        <dd class="mt-1 font-serif text-xl font-semibold text-buyer-ink">{{ $seller->created_at?->format('M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </div>
        </header>

        @if ($seller->is_on_vacation)
            <p role="status" class="mt-4 rounded-xl bg-primary-50 px-4 py-3 text-sm font-semibold text-primary-800">
                On vacation — products are visible, but purchasing is temporarily unavailable.
            </p>
        @endif

        {{-- Section tabs --}}
        @include('Buyer.Vouchers._shop-offers', ['offers' => $shopVouchers, 'wrapperClass' => 'mt-6 rounded-2xl border border-buyer-line bg-white px-5 py-4'])

        <nav aria-label="Store sections"class="mt-6 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-buyer-line">
            <a href="{{ $tabUrl('products') }}"
               @if ($tab === 'products') aria-current="page" @endif
               class="-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-[13px] font-semibold transition-colors {{ $tab === 'products' ? 'border-buyer-ink text-buyer-ink' : 'border-transparent text-buyer-ink-soft hover:text-buyer-ink' }}">
                All Products
                <span class="rounded-full bg-primary-50 px-2 py-0.5 text-[11px] text-primary-800">{{ number_format($activeProductsCount) }}</span>
            </a>
            <a href="{{ $tabUrl('reviews') }}"
               @if ($tab === 'reviews') aria-current="page" @endif
               class="-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-[13px] font-semibold transition-colors {{ $tab === 'reviews' ? 'border-buyer-ink text-buyer-ink' : 'border-transparent text-buyer-ink-soft hover:text-buyer-ink' }}">
                Customer Reviews
                <span class="rounded-full bg-primary-50 px-2 py-0.5 text-[11px] text-primary-800">{{ number_format($seller->reviews_count) }}</span>
            </a>
            <a href="{{ $tabUrl('about') }}"
               @if ($tab === 'about') aria-current="page" @endif
               class="-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-[13px] font-semibold transition-colors {{ $tab === 'about' ? 'border-buyer-ink text-buyer-ink' : 'border-transparent text-buyer-ink-soft hover:text-buyer-ink' }}">
                Brand Story &amp; About
            </a>
        </nav>

        @if ($tab === 'products')
            <div class="mt-6 space-y-5">
                {{-- Category navigation --}}
                <div class="flex flex-wrap items-center gap-2" role="navigation" aria-label="Store categories">
                    <a href="{{ route('buyer.stores.show', array_merge(['seller' => $seller], $categoryQuery)) }}"
                       @if ($selectedCategory === null && ! $uncategorized) aria-current="page" @endif
                       class="rounded-full border px-4 py-1.5 text-[12.5px] font-semibold transition-colors {{ $selectedCategory === null && ! $uncategorized ? 'border-buyer-ink bg-buyer-ink text-white' : 'border-buyer-line bg-white text-buyer-ink hover:border-buyer-ink/30' }}">
                        All
                    </a>
                    <a href="{{ route('buyer.stores.uncategorized', array_merge(['seller' => $seller], $categoryQuery)) }}"
                       @if ($uncategorized) aria-current="page" @endif
                       class="rounded-full border px-4 py-1.5 text-[12.5px] font-semibold transition-colors {{ $uncategorized ? 'border-buyer-ink bg-buyer-ink text-white' : 'border-buyer-line bg-white text-buyer-ink hover:border-buyer-ink/30' }}">
                        Uncategorized
                    </a>
                    @foreach ($storeCategories as $category)
                        <a href="{{ route('buyer.stores.categories.show', array_merge(['seller' => $seller, 'storeCategory' => $category], $categoryQuery)) }}"
                           @if ($selectedCategory?->id === $category->id) aria-current="page" @endif
                           class="rounded-full border px-4 py-1.5 text-[12.5px] font-semibold transition-colors {{ $selectedCategory?->id === $category->id ? 'border-buyer-ink bg-buyer-ink text-white' : 'border-buyer-line bg-white text-buyer-ink hover:border-buyer-ink/30' }}">
                            {{ $category->name }}
                        </a>
                        @foreach ($category->children as $child)
                            <a href="{{ route('buyer.stores.categories.show', array_merge(['seller' => $seller, 'storeCategory' => $child], $categoryQuery)) }}"
                               @if ($selectedCategory?->id === $child->id) aria-current="page" @endif
                               class="rounded-full border px-3.5 py-1.5 text-[12px] transition-colors {{ $selectedCategory?->id === $child->id ? 'border-buyer-ink bg-buyer-ink text-white' : 'border-buyer-line bg-white text-buyer-ink-soft hover:border-buyer-ink/30 hover:text-buyer-ink' }}">
                                {{ $child->name }}
                            </a>
                        @endforeach
                    @endforeach
                </div>

                {{-- Search / filter bar --}}
                <form method="GET" action="{{ route('buyer.stores.show', $seller) }}" class="flex flex-col gap-3 rounded-2xl border border-buyer-line bg-white p-3 sm:flex-row sm:items-center">
                    <input type="hidden" name="tab" value="products">
                    <div class="relative min-w-0 flex-1">
                        <i data-lucide="search" width="17" height="17" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-buyer-ink-soft"></i>
                        <input type="search" name="q" value="{{ $search }}" placeholder="Search in this store…"
                               aria-label="Search in this store"
                               class="h-11 w-full rounded-xl border border-buyer-line bg-white pl-10 pr-3 text-[13px] text-buyer-ink placeholder:text-neutral-400 focus:border-buyer-ink/40 focus:outline-none focus:ring-2 focus:ring-primary-400/30">
                    </div>

                    <label class="sr-only" for="store-category-filter">Category</label>
                    <select id="store-category-filter" name="category"
                            class="h-11 rounded-xl border border-buyer-line bg-white px-3 text-[13px] text-buyer-ink focus:border-buyer-ink/40 focus:outline-none focus:ring-2 focus:ring-primary-400/30 sm:w-44">
                        <option value="">All categories</option>
                        <option value="uncategorized" @selected($uncategorized)>Uncategorized</option>
                        @foreach ($allCategories as $category)
                            <option value="{{ $category->id }}" @selected($selectedCategory?->id === $category->id)>
                                {{ $category->parent_id ? '— ' : '' }}{{ $category->name }}
                            </option>
                        @endforeach
                    </select>

                    <label class="sr-only" for="store-sort">Sort by</label>
                    <select id="store-sort" name="sort" data-store-sort
                            class="h-11 rounded-xl border border-buyer-line bg-white px-3 text-[13px] text-buyer-ink focus:border-buyer-ink/40 focus:outline-none focus:ring-2 focus:ring-primary-400/30 sm:w-48">
                        <option value="relevance" @selected($sort === 'relevance')>Featured</option>
                        <option value="newest" @selected($sort === 'newest')>Newest arrivals</option>
                        <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                        <option value="price_asc" @selected($sort === 'price_asc')>Price: Low to High</option>
                        <option value="price_desc" @selected($sort === 'price_desc')>Price: High to Low</option>
                    </select>

                    <button type="submit"
                            class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-buyer-ink px-5 text-[13px] font-semibold text-white transition-colors hover:bg-buyer-ink/90">
                        <i data-lucide="search" width="15" height="15"></i>
                        Search
                    </button>
                </form>

                {{-- Results heading --}}
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2 class="font-serif text-xl font-semibold text-buyer-ink">{{ $activeLabel }}</h2>
                        <p class="mt-0.5 text-[12.5px] text-buyer-ink-soft">
                            Showing {{ number_format($products->firstItem() ?? 0) }}–{{ number_format($products->lastItem() ?? 0) }} of {{ number_format($products->total()) }} products
                            @if ($search !== '')
                                for “{{ $search }}”
                            @endif
                        </p>
                    </div>
                    @if ($search !== '' || $sort !== 'relevance' || $selectedCategory !== null || $uncategorized)
                        <a href="{{ route('buyer.stores.show', $seller) }}" class="text-[12.5px] font-semibold text-primary-800 underline-offset-2 hover:underline">
                            Clear filters
                        </a>
                    @endif
                </div>

                {{-- Product grid --}}
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    @forelse ($products as $product)
                        @include('Components.buyer.product-card', [
                            'product' => $product,
                            'url' => route('buyer.products.show', $product->id),
                        ])
                    @empty
                        <div class="col-span-full flex flex-col items-center justify-center rounded-3xl border border-dashed border-buyer-line bg-white px-6 py-16 text-center">
                            <span class="flex size-14 items-center justify-center rounded-full bg-primary-50 text-primary-800">
                                <i data-lucide="package-search" width="26" height="26"></i>
                            </span>
                            <h3 class="mt-4 font-serif text-lg font-semibold text-buyer-ink">No products found</h3>
                            <p class="mt-1 max-w-sm text-sm text-buyer-ink-soft">
                                {{ $search !== '' ? 'Try a different search term or clear the filters.' : 'This store has not listed products in this category yet.' }}
                            </p>
                            <a href="{{ route('buyer.stores.show', $seller) }}" class="mt-4 inline-flex min-h-10 items-center rounded-full bg-buyer-ink px-5 text-[13px] font-semibold text-white transition-colors hover:bg-buyer-ink/90">
                                View all products
                            </a>
                        </div>
                    @endforelse
                </div>

                @if ($products->hasPages())
                    <div class="flex justify-center pt-4">
                        {{ $products->links() }}
                    </div>
                @endif
            </div>
        @endif

        @if ($tab === 'reviews')
            <div class="mt-6 grid gap-6 lg:grid-cols-[280px_minmax(0,1fr)]">
                {{-- Review summary --}}
                <aside class="h-fit rounded-3xl border border-buyer-line bg-white p-6 lg:sticky lg:top-24">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">Store rating</p>
                    <div class="mt-2 flex items-end gap-2">
                        <span class="font-serif text-5xl font-semibold leading-none text-buyer-ink">{{ $ratingLabel ?? '—' }}</span>
                        <span class="pb-1 text-sm text-buyer-ink-soft">out of 5</span>
                    </div>
                    @if ($ratingLabel)
                        <div class="mt-2 flex items-center gap-1" aria-hidden="true">
                            @for ($star = 1; $star <= 5; $star++)
                                @if ($star <= $fullStars)
                                    <i class="fa-solid fa-star text-sm text-buyer-star"></i>
                                @elseif ($star === $fullStars + 1 && $hasHalf)
                                    <i class="fa-solid fa-star-half-stroke text-sm text-buyer-star"></i>
                                @else
                                    <i class="fa-regular fa-star text-sm text-neutral-300"></i>
                                @endif
                            @endfor
                        </div>
                    @endif
                    <p class="mt-3 text-[13px] text-buyer-ink-soft">
                        Based on {{ number_format($seller->reviews_count) }} {{ \Illuminate\Support\Str::plural('verified-purchase review', $seller->reviews_count) }}.
                    </p>
                    <div class="mt-5 space-y-2 border-t border-buyer-line pt-4 text-[13px] text-buyer-ink-soft">
                        <p class="flex items-center justify-between gap-3">
                            <span>Products sold</span>
                            <span class="font-semibold text-buyer-ink">{{ number_format($itemsSold) }}</span>
                        </p>
                        <p class="flex items-center justify-between gap-3">
                            <span>Active products</span>
                            <span class="font-semibold text-buyer-ink">{{ number_format($activeProductsCount) }}</span>
                        </p>
                        <p class="flex items-center justify-between gap-3">
                            <span>Joined</span>
                            <span class="font-semibold text-buyer-ink">{{ $seller->created_at?->format('M Y') ?? '—' }}</span>
                        </p>
                    </div>
                </aside>

                {{-- Review list --}}
                <section class="space-y-4" aria-label="Customer reviews">
                    @forelse ($reviews as $review)
                        @php
                            $buyerFullName = $review->buyer?->fullName() ?: '';
                            $buyerName = $buyerFullName !== '' ? $review->buyer->maskedName() : 'Verified buyer';
                            $buyerInitials = collect(preg_split('/\s+/', trim($buyerFullName)) ?: [])
                                ->filter()
                                ->take(2)
                                ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
                                ->implode('');
                        @endphp
                        <article class="rounded-2xl border border-buyer-line bg-white p-5 sm:p-6">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-50 font-serif text-[12px] font-bold text-primary-800">
                                        {{ $buyerInitials ?: 'B' }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate text-[13px] font-semibold text-buyer-ink">{{ $buyerName }}</p>
                                        <p class="mt-0.5 flex items-center gap-1 text-[11px] text-buyer-ink-soft">
                                            <i data-lucide="badge-check" width="12" height="12"></i>
                                            Verified purchase
                                        </p>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end gap-1">
                                    <div class="flex items-center gap-0.5" aria-label="{{ $review->rating }} out of 5 stars">
                                        @for ($star = 1; $star <= 5; $star++)
                                            <i class="{{ $star <= $review->rating ? 'fa-solid' : 'fa-regular' }} fa-star text-[11px] {{ $star <= $review->rating ? 'text-buyer-star' : 'text-neutral-300' }}"></i>
                                        @endfor
                                    </div>
                                    <time datetime="{{ $review->created_at?->toDateString() }}" class="text-[11px] text-buyer-ink-soft">
                                        {{ $review->created_at?->format('M j, Y') }}
                                    </time>
                                </div>
                            </div>

                            @if ($review->product)
                                <a href="{{ route('buyer.products.show', $review->product) }}" class="mt-4 inline-flex max-w-full items-center gap-1.5 truncate text-[12px] font-semibold text-primary-800 hover:underline">
                                    <i data-lucide="shopping-bag" width="13" height="13"></i>
                                    {{ $review->product->name }}
                                </a>
                            @endif

                            @if (filled($review->body))
                                <p class="mt-2 whitespace-pre-line break-words text-sm leading-relaxed text-buyer-ink">{{ $review->body }}</p>
                            @endif

                            @if ($review->photos->isNotEmpty())
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($review->photos as $photo)
                                        <img src="{{ Storage::disk('public')->url($photo->path) }}" alt="Review photo" class="size-20 rounded-lg border border-buyer-line object-cover">
                                    @endforeach
                                </div>
                            @endif

                            @if (filled($review->seller_reply))
                                <div class="mt-4 rounded-xl bg-primary-50/70 p-4">
                                    <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.06em] text-primary-800">
                                        <i data-lucide="store" width="13" height="13"></i>
                                        Seller response
                                    </p>
                                    <p class="mt-1.5 whitespace-pre-line break-words text-[13px] leading-relaxed text-buyer-ink">{{ $review->seller_reply }}</p>
                                </div>
                            @endif
                        </article>
                    @empty
                        <div class="rounded-3xl border border-dashed border-buyer-line bg-white px-6 py-16 text-center">
                            <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-primary-50 text-primary-800">
                                <i data-lucide="message-square-heart" width="26" height="26"></i>
                            </span>
                            <h2 class="mt-4 font-serif text-lg font-semibold text-buyer-ink">No reviews yet</h2>
                            <p class="mt-1 text-sm text-buyer-ink-soft">Be the first to share your experience with this store.</p>
                        </div>
                    @endforelse

                    @if ($reviews->hasPages())
                        <div class="flex justify-center pt-2">
                            {{ $reviews->links() }}
                        </div>
                    @endif
                </section>
            </div>
        @endif

        @if ($tab === 'about')
            <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                <article class="rounded-3xl border border-buyer-line bg-white p-6 sm:p-8">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.08em] text-buyer-ink-soft">Brand story</p>
                    <h2 class="mt-2 font-serif text-2xl font-semibold text-buyer-ink">About {{ $seller->business_name }}</h2>
                    @if (filled($seller->store_description))
                        <p class="mt-4 whitespace-pre-line break-words text-sm leading-relaxed text-buyer-ink-soft">{{ trim($seller->store_description) }}</p>
                    @else
                        <p class="mt-4 text-sm text-buyer-ink-soft">This store has not added a brand story yet.</p>
                    @endif

                    @if ($allCategories->isNotEmpty())
                        <div class="mt-8 border-t border-buyer-line pt-6">
                            <h3 class="font-serif text-lg font-semibold text-buyer-ink">Shop by category</h3>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($allCategories as $category)
                                    <a href="{{ route('buyer.stores.categories.show', [$seller, $category]) }}"
                                       class="rounded-full border border-buyer-line bg-white px-4 py-1.5 text-[12.5px] font-semibold text-buyer-ink transition-colors hover:border-buyer-ink/30 hover:bg-neutral-50">
                                        {{ $category->name }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </article>

                <aside class="h-fit space-y-4 lg:sticky lg:top-24">
                    <div class="rounded-3xl border border-buyer-line bg-white p-6">
                        <h2 class="font-serif text-lg font-semibold text-buyer-ink">Store details</h2>
                        <dl class="mt-4 space-y-3 text-[13px]">
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-buyer-ink-soft">Store rating</dt>
                                <dd class="font-semibold text-buyer-ink">{{ $ratingLabel ? $ratingLabel.' / 5' : '—' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-buyer-ink-soft">Total products</dt>
                                <dd class="font-semibold text-buyer-ink">{{ number_format($activeProductsCount) }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-buyer-ink-soft">Items sold</dt>
                                <dd class="font-semibold text-buyer-ink">{{ number_format($itemsSold) }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-buyer-ink-soft">Joined</dt>
                                <dd class="font-semibold text-buyer-ink">{{ $seller->created_at?->format('M j, Y') ?? '—' }}</dd>
                            </div>
                            @if ($location)
                                <div class="flex items-start justify-between gap-4">
                                    <dt class="text-buyer-ink-soft">Ships from</dt>
                                    <dd class="text-right font-semibold text-buyer-ink">{{ $location }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>

                    <div class="rounded-3xl border border-buyer-line bg-buyer-ink p-6 text-white">
                        <h2 class="font-serif text-lg font-semibold">Questions about this store?</h2>
                        <p class="mt-2 text-[13px] leading-relaxed text-white/75">Message the seller directly from any product page.</p>
                        <a href="{{ route('buyer.products.index', ['seller' => $seller->id]) }}"
                           class="mt-4 inline-flex min-h-10 items-center gap-2 rounded-full bg-white px-4 text-[12.5px] font-semibold text-buyer-ink transition-colors hover:bg-primary-50">
                            <i data-lucide="shopping-bag" width="14" height="14"></i>
                            Browse all products
                        </a>
                    </div>
                </aside>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    (() => {
        const sort = document.querySelector('[data-store-sort]');
        sort?.addEventListener('change', () => sort.form?.submit());

        const share = document.querySelector('[data-store-share]');
        const status = document.querySelector('[data-store-share-status]');
        if (!share || !status) return;

        share.addEventListener('click', async () => {
            const url = share.dataset.shareUrl;
            const title = share.dataset.shareTitle || document.title;

            try {
                if (navigator.share) {
                    await navigator.share({ title, url });
                    return;
                }

                await navigator.clipboard.writeText(url);
                status.textContent = 'Store link copied to clipboard.';
            } catch (error) {
                if (error?.name === 'AbortError') return;
                status.textContent = 'Could not share automatically. Copy this link: ' + url;
            }

            window.setTimeout(() => { status.textContent = ''; }, 4000);
        });
    })();
</script>
@endpush
