{{-- resources/views/Buyer/Layouts/navbar.blade.php
     Included by Buyer/Layouts/app.blade.php on every buyer page.
     ASSUMPTION: buyer auth guard doesn't exist yet (flagged in earlier
     rounds), so the account name/avatar below fall back to placeholders.
     Swap Auth::guard('buyer')->user() in once that guard is built. --}}

@php
    $buyer = auth('buyer')->user(); // will be null until the buyer guard exists
    $buyerName = $buyer ? $buyer->fullName() : 'Guest';
    $buyerInitial = $buyer ? strtoupper(substr($buyer->first_name, 0, 1)) : 'G';
@endphp

<div class="utility-bar">Free shipping for orders over Rp 500.000 · New arrivals every Friday</div>

<header>
  <div class="header-row">
    <a href="{{ route('buyer.home') }}" class="logo">
      <div class="logo-mark">Z</div>
      <span class="logo-word serif">Zefanya</span>
    </a>

    {{-- NOTE: this dropdown's open/close was never wired up in the original
         mockup (no JS toggle existed for it). Left as-is; wire up if the
         "browse by category" filter is meant to work from here. --}}
    <div class="cat-select" id="catSelectBtn">
      <span>All Categories</span><i class="fa-solid fa-chevron-down"></i>
    </div>

    <form class="search-wrap" action="{{ route('buyer.products.index') }}" method="GET">
      <input type="text" name="q" placeholder="Search for products, brands, or stores..." value="{{ request('q') }}">
      <button type="submit" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>

    <div class="header-icons">
      <button class="icon-btn" title="Wishlist"><i class="fa-regular fa-heart"></i><span class="badge">3</span></button>

      <div class="header-icon-wrap">
        <button class="icon-btn" title="Chat" onclick="toggleChat()"><i class="fa-regular fa-comment-dots"></i><span class="badge">2</span></button>
      </div>

      <button class="icon-btn" title="Cart" onclick="toggleCart()"><i class="fa-solid fa-bag-shopping"></i><span class="badge" id="cartCount">2</span></button>

      <div class="header-icon-wrap">
        <button class="account-btn" onclick="toggleAccount()">
          <div class="avatar">{{ $buyerInitial }}</div>
          <div class="account-name">{{ $buyerName }}<small>Buyer account</small></div>
          <i class="fa-solid fa-chevron-down" style="font-size:10px;color:var(--neutral)"></i>
        </button>
        <div class="dropdown" id="accountDropdown">
          <a href="{{ route('buyer.account.index') }}"><i class="fa-regular fa-user"></i> Account management</a>
          <a href="{{ route('buyer.orders.index') }}"><i class="fa-solid fa-box"></i> My orders</a>
          <a href="#"><i class="fa-regular fa-heart"></i> Wishlist</a>
          <a href="{{ route('buyer.chat.index') }}"><i class="fa-regular fa-comment-dots"></i> Messages</a>
          <hr>
          <form method="POST" action="{{ route('buyer.logout') }}">
              @csrf
              <button type="submit" class="dd-item logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Log out</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</header>
