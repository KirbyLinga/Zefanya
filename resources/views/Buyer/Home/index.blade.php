@extends('Buyer.Layouts.app')

@section('title', 'Home')

@push('styles')
    @vite(['resources/css/buyer/home.css'])
@endpush

@section('content')

{{-- HERO / FLASH SALE --}}
<div class="hero">
  <div class="hero-inner">
    <div class="hero-text">
      <div class="eyebrow">Welcome back, {{ $buyerName ?? 'there' }}</div>
      <h1>Today's flash sale is live — up to 40% off</h1>
      <p>Handpicked home, beauty and lifestyle finds, refreshed every day. Stock moves fast once the timer starts.</p>
      <button class="hero-cta">Shop the sale <i class="fa-solid fa-arrow-right"></i></button>
      <div class="countdown">
        <div class="box"><b id="hh">04</b><span>HRS</span></div>
        <div class="box"><b id="mm">18</b><span>MIN</span></div>
        <div class="box"><b id="ss">52</b><span>SEC</span></div>
      </div>
    </div>
    <div class="hero-art"><i class="fa-solid fa-gift"></i></div>
  </div>
</div>

<div class="container">

  {{-- CATEGORIES TEASER — full browsing lives at Categories/index --}}
  <section>
    <div class="section-head">
      <div>
        <h2>Browse categories</h2>
        <div class="sub">Everything you need, sorted the way you shop</div>
      </div>
      <a class="view-all" href="{{ route('buyer.categories.index') }}">View all <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="cat-row">
      {{-- TODO: hardcoded 8-category teaser matching the original mockup's
           icon set (fa-solid), not the 14-category DB list (lucide icon
           names from the categories table). Once this is wired to real
           data, either map lucide->fontawesome names or standardize on one
           icon library across the whole app. --}}
      @foreach ([
          ['icon' => 'book-open', 'label' => 'Books & Media'],
          ['icon' => 'heart-pulse', 'label' => 'Health & Beauty'],
          ['icon' => 'mountain', 'label' => 'Sports & Outdoors'],
          ['icon' => 'seedling', 'label' => 'Home & Garden'],
          ['icon' => 'shirt', 'label' => 'Fashion & Apparel'],
          ['icon' => 'child-reaching', 'label' => 'Kids & Baby'],
          ['icon' => 'laptop', 'label' => 'Electronics'],
          ['icon' => 'basket-shopping', 'label' => 'Groceries'],
      ] as $cat)
        <div class="cat-item">
          <div class="ic"><i class="fa-solid fa-{{ $cat['icon'] }}"></i></div>
          <span>{{ $cat['label'] }}</span>
        </div>
      @endforeach
    </div>
  </section>

  {{-- FLASH SALE GRID --}}
  <section>
    <div class="section-head">
      <div style="display:flex;align-items:center;">
        <h2>Flash Sale</h2>
        <div class="timer-chip"><i class="fa-regular fa-clock"></i> Ends in <b id="chipTimer">04:18:52</b></div>
      </div>
      <a class="view-all" href="{{ route('buyer.products.index') }}">See all deals <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="grid" id="flashGrid">
      {{-- populated by home.js — see its TODO about replacing with real product data --}}
    </div>
  </section>

  {{-- RECOMMENDED / TABS --}}
  <section>
    <div class="section-head">
      <div><h2>Picked for you</h2><div class="sub">Based on your recent browsing</div></div>
    </div>
    <div class="tabs">
      <button class="tab active">Best Seller</button>
      <button class="tab">New Arrivals</button>
      <button class="tab">Top Rated</button>
      <button class="tab">Official Store</button>
      <button class="tab">Under Rp100.000</button>
    </div>
    <div class="grid" id="recoGrid"></div>
  </section>

  {{-- ORDER STATUS --}}
  <section>
    <div class="section-head">
      <div><h2>Your orders</h2><div class="sub">Track what's on the way</div></div>
      <a class="view-all" href="{{ route('buyer.orders.index') }}">View all orders <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="orders-panel">
      {{-- TODO: hardcoded single order matching the mockup. Once Orders
           schema exists, show the buyer's most recent active order here,
           or omit this section entirely if they have none. --}}
      <div class="order-top">
        <div class="order-id">Order <b>#ZF-10432</b> · placed 3 Sep 2026</div>
        <a class="track-link" href="#"><i class="fa-solid fa-location-dot"></i> Track shipment</a>
      </div>
      <div class="stepper">
        <div class="step done"><div class="dot"><i class="fa-solid fa-check"></i></div><label>Order placed</label><div class="step-line"></div></div>
        <div class="step done"><div class="dot"><i class="fa-solid fa-check"></i></div><label>Packed</label><div class="step-line"></div></div>
        <div class="step current"><div class="dot"><i class="fa-solid fa-truck"></i></div><label>In transit</label><div class="step-line"></div></div>
        <div class="step"><div class="dot"><i class="fa-solid fa-route"></i></div><label>Out for delivery</label><div class="step-line"></div></div>
        <div class="step"><div class="dot"><i class="fa-solid fa-house"></i></div><label>Delivered</label></div>
      </div>
      <div class="order-footer">
        <div class="items">3 items · Ceramic Mug Set, Linen Throw Pillow +1</div>
        <div style="display:flex;gap:10px;">
          <button class="ghost-btn">Message seller</button>
          <a href="{{ route('buyer.orders.index') }}" class="fill-btn" style="display:inline-block;text-decoration:none;">View details</a>
        </div>
      </div>
    </div>
  </section>

  {{-- BEST SELLING STORES --}}
  <section>
    <div class="section-head">
      <div><h2>Best selling stores</h2><div class="sub">Trusted sellers our buyers keep coming back to</div></div>
      <a class="view-all" href="#">View all <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="stores">
      {{-- TODO: hardcoded stores matching the mockup. Replace with real
           Seller data (business_name, rating, line_of_business) once
           sellers can be approved and have storefronts. --}}
      <div class="store-card"><div class="store-logo">HN</div><div><div class="store-name">Hearth & Nest</div><div class="store-meta"><i class="fa-solid fa-star"></i> 4.9 · Home & Living</div></div></div>
      <div class="store-card"><div class="store-logo">PB</div><div><div class="store-name">Pure Botanics</div><div class="store-meta"><i class="fa-solid fa-star"></i> 4.8 · Beauty</div></div></div>
      <div class="store-card"><div class="store-logo">LT</div><div><div class="store-name">Little Tumble</div><div class="store-meta"><i class="fa-solid fa-star"></i> 4.9 · Kids & Baby</div></div></div>
      <div class="store-card"><div class="store-logo">FG</div><div><div class="store-name">Field & Grove</div><div class="store-meta"><i class="fa-solid fa-star"></i> 4.7 · Outdoors</div></div></div>
    </div>
  </section>

</div>

@endsection

@push('scripts')
    @vite(['resources/js/buyer/home.js'])
@endpush
