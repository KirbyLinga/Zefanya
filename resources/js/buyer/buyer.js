// resources/js/buyer/buyer.js
// Shared across every Buyer page: the account dropdown, cart drawer,
// quick-view modal, chat panel, and toast � all live in the layout
// (Buyer/Layouts/app.blade.php), so their behavior belongs here rather
// than in a page-specific file like home.js.

function closeAllPanels(){
  document.getElementById('cartDrawer').classList.remove('open');
  document.getElementById('overlay').classList.remove('open');
  document.getElementById('accountDropdown').classList.remove('open');
}

function toggleAccount(){
  document.getElementById('accountDropdown').classList.toggle('open');
}

function toggleCart(){
  document.getElementById('cartDrawer').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}

function toggleChat(){
  document.getElementById('chatPanel').classList.toggle('open');
}

// Expose globally so navbar inline handlers and other scripts can call them.
// (Vite may bundle these in a scope that prevents implicit globals.)
window.toggleAccount = toggleAccount;
window.toggleCart = toggleCart;
window.toggleChat = toggleChat;
window.closeAllPanels = closeAllPanels;


// Wire up navbar buttons (replaces inline onclick handlers which don't work with ES modules)
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-account-toggle').forEach(el => el.addEventListener('click', toggleAccount));
  document.querySelectorAll('.js-cart-toggle').forEach(el => el.addEventListener('click', toggleCart));
  document.querySelectorAll('.js-chat-toggle').forEach(el => el.addEventListener('click', toggleChat));

  document.querySelector('.js-overlay')?.addEventListener('click', closeAllPanels);
  document.querySelector('.js-qv-close')?.addEventListener('click', closeQuickView);
  document.querySelector('.js-qv-overlay')?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeQuickView();
  });

  // Close dropdown when clicking outside
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.header-icon-wrap') && !e.target.closest('.account-btn')) {
      document.getElementById('accountDropdown')?.classList.remove('open');
    }
  });

  // Quick-view quantity stepper
  document.querySelector('.js-qv-qty-up')?.addEventListener('click', () => stepQty(1));
  document.querySelector('.js-qv-qty-down')?.addEventListener('click', () => stepQty(-1));

  // Quick-view add to cart
  document.querySelector('.js-qv-add')?.addEventListener('click', function () {
    const productId = parseInt(this.dataset.productId, 10) || 1;
    addToCart(productId, qvQty);
  });
});

function openQuickView(){ document.getElementById('qvOverlay').classList.add('open'); }
function closeQuickView(){ document.getElementById('qvOverlay').classList.remove('open'); }

document.querySelectorAll('.swatch').forEach(s => s.addEventListener('click', () => {
  document.querySelectorAll('.swatch').forEach(x => x.classList.remove('selected'));
  s.classList.add('selected');
}));
document.querySelectorAll('.size-opt').forEach(s => s.addEventListener('click', () => {
  document.querySelectorAll('.size-opt').forEach(x => x.classList.remove('selected'));
  s.classList.add('selected');
}));

let qvQty = 1;
function stepQty(d){
  qvQty = Math.max(1, qvQty + d);
  document.getElementById('qvQty').textContent = qvQty;
}

// TODO: cartCount currently starts at a hardcoded 2 (matching the original
// mockup) and only increments client-side. Once Cart is DB/session-backed,
// this should read the real count on page load instead.
let cartCount = 2;

// Pending cart action � stores what a guest tried to add so we can retry after login.
// Set by addToCart() before opening the login modal; consumed by retryPendingCartAction().
let pendingCartAction = null;

/**
 * Add a product to the cart. Uses fetch/AJAX so we can intercept 401 responses
 * and open the login modal instead of following a redirect.
 *
 * @param {number} productId - the product ID to add
 * @param {number} quantity  - quantity to add (defaults to 1)
 */
function addToCart(productId, quantity = 1) {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
  const url = '/buyer/cart/add';

  fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrfToken,
      'Accept': 'application/json',
    },
    body: JSON.stringify({ product_id: productId, quantity: quantity }),
  })
  .then(res => {
    if (res.status === 401) {
      // Guest � save the pending action and open the login modal
      pendingCartAction = { productId, quantity };
      if (typeof window.openLoginModal === 'function') {
        window.openLoginModal();
      }
      return null;
    }
    if (!res.ok) {
      throw new Error('Cart add failed: ' + res.status);
    }
    return res.json();
  })
  .then(data => {
    if (!data) return; // was a 401, modal opened
    if (data.cart_count !== undefined) {
      cartCount = data.cart_count;
      const el = document.getElementById('cartCount');
      if (el) el.textContent = cartCount;
    }
    closeQuickView();
    showToast();
  })
  .catch(err => {
    console.error('Add to cart failed:', err);
  });
}

function showToast() {
  const toast = document.getElementById('toast');
  if (!toast) return;
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 1800);
}

/**
 * Retry the pending cart action after successful login.
 * Called by the login modal's success handler.
 */
function retryPendingCartAction() {
  if (!pendingCartAction) return;
  const { productId, quantity } = pendingCartAction;
  pendingCartAction = null;
  addToCart(productId, quantity);
}