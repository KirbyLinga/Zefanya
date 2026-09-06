{{-- resources/views/Buyer/Layouts/footer.blade.php --}}

<footer>
  <div class="container">
    <div class="footer-grid">
      <div>
        <div class="foot-logo">Zefanya</div>
        <p class="foot-desc">Everything you need, all in one place — from daily essentials to unexpected finds, delivered straight to your door.</p>
      </div>
      <div>
        <h4>Shop</h4>
        <ul>
          <li><a href="{{ route('buyer.categories.index') }}">All categories</a></li>
          <li><a href="#">Flash sale</a></li>
          <li><a href="#">New arrivals</a></li>
          <li><a href="#">Best selling stores</a></li>
        </ul>
      </div>
      <div>
        <h4>Account</h4>
        <ul>
          <li><a href="{{ route('buyer.orders.index') }}">My orders</a></li>
          <li><a href="#">Wishlist</a></li>
          <li><a href="{{ route('buyer.chat.index') }}">Messages</a></li>
          <li><a href="{{ route('buyer.account.index') }}">Account settings</a></li>
        </ul>
      </div>
      <div>
        <h4>Support</h4>
        <ul>
          <li><a href="#">Help center</a></li>
          <li><a href="#">Shipping info</a></li>
          <li><a href="#">Returns</a></li>
          <li><a href="#">Contact us</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© {{ date('Y') }} Zefanya. All rights reserved.</span>
      <span>Made for buyers who love finding the perfect thing.</span>
    </div>
  </div>
</footer>
