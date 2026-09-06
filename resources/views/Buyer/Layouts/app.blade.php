<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Zefanya — @yield('title', 'Buyer')</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
@vite(['resources/css/buyer/buyer.css'])
@stack('styles')
</head>
<body>

@include('Buyer.Layouts.navbar')

@yield('content')

@include('Buyer.Layouts.footer')


{{-- Global overlay widgets — present on every buyer page, not just Home,
     since product cards (which trigger quick-view) and the cart/chat
     launchers live in the navbar/every product grid. --}}

<div class="overlay" id="overlay" onclick="closeAllPanels()"></div>

<aside class="drawer" id="cartDrawer">
  <div class="drawer-head"><h3 class="serif">Your cart</h3><button class="drawer-close" onclick="toggleCart()"><i class="fa-solid fa-xmark"></i></button></div>
  <div class="drawer-body">
    {{-- TODO: hardcoded cart contents, matching the original mockup.
         Replace with a real @foreach over cart items once Cart is built
         (see BUYER_STRUCTURE.md's open item on session vs. DB-backed cart). --}}
    <div class="cart-item">
      <div class="cart-thumb"><i class="fa-solid fa-mug-hot"></i></div>
      <div class="cart-item-info">
        <div class="cname">Ceramic Mug Set (4pc)</div>
        <div class="cvar">Color: Sage · Set of 4</div>
        <div class="qty-stepper"><button>-</button><span>1</span><button>+</button></div>
      </div>
      <div class="cprice">Rp185.000</div>
    </div>
    <div class="cart-item">
      <div class="cart-thumb"><i class="fa-solid fa-couch"></i></div>
      <div class="cart-item-info">
        <div class="cname">Linen Blend Throw Pillow</div>
        <div class="cvar">Color: Blush · 45x45cm</div>
        <div class="qty-stepper"><button>-</button><span>2</span><button>+</button></div>
      </div>
      <div class="cprice">Rp250.000</div>
    </div>

    <div>
      <div class="opt-label" style="margin-bottom:8px;">VOUCHER</div>
      <div class="voucher-row"><input type="text" placeholder="Enter voucher code"><button>Apply</button></div>
    </div>

    <div>
      <div class="opt-label" style="margin-bottom:8px;">PAYMENT METHOD</div>
      <div class="pay-options">
        <label class="pay-option"><input type="radio" name="pay" checked> Bank transfer</label>
        <label class="pay-option"><input type="radio" name="pay"> E-wallet</label>
        <label class="pay-option"><input type="radio" name="pay"> Cash on delivery</label>
      </div>
    </div>
  </div>
  <div class="drawer-foot">
    <div class="sum-row"><span>Subtotal</span><span>Rp685.000</span></div>
    <div class="sum-row"><span>Voucher discount</span><span>−Rp50.000</span></div>
    <div class="sum-row total"><span>Total</span><span>Rp635.000</span></div>
    <a href="{{ route('buyer.checkout.index') }}" class="checkout-btn" style="display:block;text-align:center;">Place order</a>
  </div>
</aside>

<div class="modal-overlay" id="qvOverlay" onclick="if(event.target===this)closeQuickView()">
  {{-- TODO: static placeholder product, matching the original mockup.
       Once Products exist, populate this dynamically (data attributes on
       the card + JS, or a fetch) instead of hardcoding "Ceramic Mug Set". --}}
  <div class="qv-modal">
    <div class="qv-media"><i class="fa-solid fa-mug-hot"></i></div>
    <div class="qv-info">
      <button class="qv-close" onclick="closeQuickView()"><i class="fa-solid fa-xmark"></i></button>
      <h3>Ceramic Mug Set (4pc)</h3>
      <div class="qv-rating"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i> 4.8 · 2,104 sold</div>
      <div class="qv-price">Rp185.000<span class="qv-old">Rp250.000</span></div>

      <div style="margin-top:20px;">
        <div class="opt-label">COLOR</div>
        <div class="swatches">
          <div class="swatch selected" style="background:var(--tertiary)"></div>
          <div class="swatch" style="background:var(--primary)"></div>
          <div class="swatch" style="background:var(--neutral)"></div>
        </div>
      </div>

      <div>
        <div class="opt-label">SET SIZE</div>
        <div class="size-opts">
          <button class="size-opt">2pc</button>
          <button class="size-opt selected">4pc</button>
          <button class="size-opt">6pc</button>
        </div>
      </div>

      <div class="opt-label">QUANTITY</div>
      <div class="qty-stepper" style="margin-bottom:6px;"><button onclick="stepQty(-1)">-</button><span id="qvQty">1</span><button onclick="stepQty(1)">+</button></div>

      <div class="qv-actions">
        <button class="qv-add" onclick="addToCart()">Add to cart</button>
        <button class="icon-btn" style="border:1px solid var(--line);"><i class="fa-regular fa-heart"></i></button>
      </div>
    </div>
  </div>
</div>

<div class="chat-panel" id="chatPanel">
  {{-- TODO: static conversation, matching the original mockup. Wire up to
       Chat/index and Chat/show once the chat backend exists. --}}
  <div class="chat-head"><span>Hearth & Nest · Store chat</span><button onclick="toggleChat()"><i class="fa-solid fa-xmark"></i></button></div>
  <div class="chat-body">
    <div class="msg store">Hi! Thanks for your order 🌿 it's been packed and handed to courier.</div>
    <div class="msg me">Great, do you know when it'll arrive?</div>
    <div class="msg store">Estimated 2 more days — I'll update you once it's out for delivery.</div>
  </div>
  <div class="chat-input"><input type="text" placeholder="Type a message..."><button><i class="fa-solid fa-paper-plane"></i></button></div>
</div>

<div class="toast" id="toast"><i class="fa-solid fa-circle-check"></i> Added to cart</div>

@vite(['resources/js/buyer/buyer.js'])
@stack('scripts')
</body>
</html>
