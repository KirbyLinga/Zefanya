// resources/js/buyer/buyer.js
// Shared across every Buyer page: cart drawer, quick-view modal, chat panel,
// and toast — all live in the layout (Buyer/Layouts/app.blade.php), so their
// behaviour belongs here rather than in a page-specific file like home.js.

function closeAllPanels() {
  document.getElementById('cartDrawer').classList.remove('open');
  document.getElementById('overlay').classList.remove('open');
  document.getElementById('accountDropdown')?.classList.remove('open');
}

function toggleAccount() {
  document.getElementById('accountDropdown')?.classList.toggle('open');
}

function toggleCart() {
  document.getElementById('cartDrawer').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
}

function toggleChat() {
  document.getElementById('chatPanel').classList.toggle('open');
}

// Expose globally so navbar inline handlers and other scripts can call them.
window.toggleAccount  = toggleAccount;
window.toggleCart     = toggleCart;
window.toggleChat     = toggleChat;
window.closeAllPanels = closeAllPanels;


// Wire up layout buttons on DOMContentLoaded.
// NOTE: the account dropdown (.js-account-toggle) is owned by the navbar
// component's own inline script so it works on every layout. Do NOT add a
// second listener here — it would double-toggle and cancel out.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-cart-toggle').forEach(el =>
    el.addEventListener('click', toggleCart)
  );
  document.querySelectorAll('.js-chat-toggle').forEach(el =>
    el.addEventListener('click', toggleChat)
  );

  document.querySelector('.js-overlay')?.addEventListener('click', closeAllPanels);
  document.querySelector('.js-qv-close')?.addEventListener('click', closeQuickView);
  document.querySelector('.js-qv-overlay')?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeQuickView();
  });

  // Close account dropdown when clicking outside.
  // Must NOT fire when the user clicks the toggle button itself — that is
  // owned by the navbar's inline script. If we close here on the same click
  // the toggle just opened, it cancels out. Guard with .js-account-toggle.
  document.addEventListener('click', (e) => {
    const dropdown = document.getElementById('accountDropdown');
    const toggle   = document.querySelector('.js-account-toggle');
    if (!dropdown) return;
    if (toggle   && toggle.contains(e.target))   return; // let navbar inline script handle
    if (dropdown &&  dropdown.contains(e.target)) return; // click inside — keep open
    dropdown.classList.remove('open');
    toggle?.setAttribute('aria-expanded', 'false');
  });

  // Quick-view quantity stepper
  document.querySelector('.js-qv-qty-up')?.addEventListener('click',  () => stepQty(1));
  document.querySelector('.js-qv-qty-down')?.addEventListener('click', () => stepQty(-1));

  // Quick-view add to cart
  document.querySelector('.js-qv-add')?.addEventListener('click', function () {
    const productId = parseInt(this.dataset.productId, 10) || 1;
    addToCart(productId, qvQty);
  });

  // Open quick-view: delegated on document so it works for dynamically-added cards.
  // Passes the product id so openQuickView() can set the PDP link and cart button.
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('.js-open-qv');
    if (!trigger) return;
    e.preventDefault();
    const productId = parseInt(trigger.dataset.productId, 10) || null;
    openQuickView(productId);
  });
});

// ── Quick-view ───────────────────────────────────────────────────────────────
function openQuickView(productId) {
  // Update the Add-to-cart button's product id
  const addBtn = document.querySelector('.js-qv-add');
  if (addBtn && productId) addBtn.dataset.productId = productId;

  // Update "View full details" link to point at the clicked product's PDP
  const pdpLink = document.getElementById('qv-pdp-link');
  if (pdpLink && productId) {
    pdpLink.href = '/buyer/products/' + productId;
  }

  document.getElementById('qvOverlay').classList.add('open');
}
function closeQuickView() { document.getElementById('qvOverlay').classList.remove('open'); }

// Swatch / size-opt selection (quick-view only; PDP uses js-pdp-color / js-pdp-size)
document.querySelectorAll('.swatch').forEach(s => s.addEventListener('click', () => {
  document.querySelectorAll('.swatch').forEach(x => x.classList.remove('selected'));
  s.classList.add('selected');
}));
document.querySelectorAll('.size-opt').forEach(s => s.addEventListener('click', () => {
  document.querySelectorAll('.size-opt').forEach(x => x.classList.remove('selected'));
  s.classList.add('selected');
}));

let qvQty = 1;
function stepQty(d) {
  qvQty = Math.max(1, qvQty + d);
  document.getElementById('qvQty').textContent = qvQty;
}

// ── Cart ─────────────────────────────────────────────────────────────────────
// Real server-side count, rendered into the navbar badge by the
// 'Components.navbar' view composer (AppServiceProvider). Read it from the DOM
// on load instead of hardcoding a mockup value; addToCart() keeps it in sync.
let cartCount = parseInt(document.getElementById('cartCount')?.textContent ?? '0', 10) || 0;

// Pending cart action — stores a guest's attempted add so we can retry after login.
let pendingCartAction = null;

/**
 * Add a product to the cart via AJAX.
 * Returns the fetch promise so callers can .then() on success
 * (e.g. Buy Now redirect in product-show.js). Backwards-compatible:
 * existing callers that ignore the return value are unaffected.
 *
 * @param {number} productId
 * @param {number} quantity  (default 1)
 * @returns {Promise<object|null>}
 */
function addToCart(productId, quantity = 1) {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

  const promise = fetch('/buyer/cart/add', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrfToken,
      'Accept': 'application/json',
    },
    body: JSON.stringify({ product_id: productId, quantity }),
  })
  .then(res => {
    if (res.status === 401) {
      // Guest — save the pending action and open the login modal
      pendingCartAction = { productId, quantity };
      if (typeof window.openLoginModal === 'function') {
        window.openLoginModal();
      }
      return null; // signal: not added (guest)
    }
    if (!res.ok) throw new Error('Cart add failed: ' + res.status);
    return res.json();
  })
  .then(data => {
    if (!data) return null; // 401 path — modal already opened
    if (data.cart_count !== undefined) {
      cartCount = data.cart_count;
      const el = document.getElementById('cartCount');
      if (el) el.textContent = cartCount;
    }
    closeQuickView();
    showToast();
    return data; // truthy — callers can use this to confirm success
  })
  .catch(err => {
    console.error('Add to cart failed:', err);
    return null;
  });

  return promise;
}

/**
 * Show the global toast.
 *
 * @param {string} [message]  Optional text; defaults to the original
 *                            "Added to cart" wording so existing callers
 *                            behave exactly as before.
 */
function showToast(message) {
  const toast = document.getElementById('toast');
  if (!toast) return;
  const label = document.getElementById('toastMessage');
  if (label && typeof message === 'string' && message !== '') {
    label.textContent = message;
  } else if (label) {
    label.textContent = 'Added to cart';
  }
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 1800);
}

/**
 * Retry the pending cart action after a guest successfully logs in.
 * Called by the login modal's success handler.
 */
function retryPendingCartAction() {
  if (!pendingCartAction) return;
  const { productId, quantity } = pendingCartAction;
  pendingCartAction = null;
  addToCart(productId, quantity);
}

window.openQuickView          = openQuickView;
window.closeQuickView         = closeQuickView;
window.addToCart              = addToCart;
window.retryPendingCartAction = retryPendingCartAction;
