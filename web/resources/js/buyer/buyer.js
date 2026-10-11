// resources/js/buyer/buyer.js
// Shared across every Buyer page: cart drawer and toast — both live in the
// layout (Buyer/Layouts/app.blade.php), so their behaviour belongs here
// rather than in a page-specific file like home.js.

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

// Expose globally so navbar inline handlers and other scripts can call them.
window.toggleAccount  = toggleAccount;
window.toggleCart     = toggleCart;
window.closeAllPanels = closeAllPanels;


// Wire up layout buttons on DOMContentLoaded.
// NOTE: the account dropdown (.js-account-toggle) is owned by the navbar
// component's own inline script so it works on every layout. Do NOT add a
// second listener here — it would double-toggle and cancel out.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-cart-toggle').forEach(el =>
    el.addEventListener('click', toggleCart)
  );

  document.querySelector('.js-overlay')?.addEventListener('click', closeAllPanels);
});

// ── Cart ─────────────────────────────────────────────────────────────────────
// Real server-side count, rendered into the navbar badge by the
// 'Components.navbar' view composer (AppServiceProvider). Read it from the DOM
// on load instead of hardcoding a mockup value; addToCart() keeps it in sync.
let cartCount = parseInt(document.getElementById('cartCount')?.textContent ?? '0', 10) || 0;

// Pending cart action — stores a guest's attempted action so we can retry after login.
// Uses sessionStorage so it survives the login page reload (the login modal form
// submits as a classic POST → full page load → JS context is reset).
let pendingCartAction = null;

// 10-minute expiry in milliseconds.
const PENDING_ACTION_TTL = 10 * 60 * 1000;

function savePendingAction(action) {
    pendingCartAction = action;
    const payload = JSON.stringify({
        action: action,
        ts: Date.now(),
    });
    sessionStorage.setItem('zf_pending_cart_action', payload);
}

function loadPendingAction() {
    if (pendingCartAction !== null) return pendingCartAction;

    const raw = sessionStorage.getItem('zf_pending_cart_action');
    if (!raw) return null;

    try {
        const { action, ts } = JSON.parse(raw);
        if (!action) return null;

        // Expired — clear and bail so a stale action never fires.
        if (Date.now() - ts > PENDING_ACTION_TTL) {
            clearPendingAction();
            return null;
        }

        pendingCartAction = action;
        return action;
    } catch {
        return null;
    }
}

function clearPendingAction() {
    pendingCartAction = null;
    sessionStorage.removeItem('zf_pending_cart_action');
}

/**
 * Add a product to the cart via AJAX.
 * Returns the fetch promise so callers can .then() on success
 * (e.g. Buy Now redirect in product-show.js). Backwards-compatible:
 * existing callers that ignore the return value are unaffected.
 *
 * @param {number} productId
 * @param {number} quantity  (default 1)
 * @param {number|null} variantId  specific combination (PDP variant mode)
 * @param {HTMLElement|null} sourceButton  button element for animation context
 * @returns {Promise<object|null>}
 */
function addToCart(productId, quantity = 1, variantId = null, sourceButton = null) {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

  const promise = fetch('/buyer/cart/add', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrfToken,
      'Accept': 'application/json',
    },
    body: JSON.stringify({ product_id: productId, quantity, variant_id: variantId }),
  })
  .then(res => {
    if (res.status === 401) {
      // Guest — save the pending action and open the login modal
      savePendingAction({ type: 'addToCart', productId, quantity, variantId });
      if (typeof window.openLoginModal === 'function') {
        window.openLoginModal();
      }
      return null;
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
    // Pass product details to toast
    if (data.product_name && data.product_price) {
      showToast(data.product_name, data.product_price);
    } else {
      showToast();
    }
    return data; // truthy — callers can use this to confirm success
  })
  .catch(err => {
    console.error('Add to cart failed:', err);
    return null;
  });

  return promise;
}

/**
 * "Buy Now" from the PDP — creates a single-item cart line and redirects
 * to the checkout page scoped to that line only.
 *
 * @param {number} productId
 * @param {number} quantity  (default 1)
 * @param {number|null} variantId  specific combination (variant mode)
 * @returns {Promise<object|null>}
 */
function buyNow(productId, quantity = 1, variantId = null) {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

  const promise = fetch('/buyer/checkout/buy-now', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrfToken || '',
      'Accept': 'application/json',
    },
    body: JSON.stringify({ product_id: productId, quantity, variant_id: variantId }),
  })
  .then(res => {
    if (res.status === 401) {
      // Guest — save the pending Buy Now action and open the login modal
      savePendingAction({ type: 'buyNow', productId, quantity, variantId });
      if (typeof window.openLoginModal === 'function') {
        window.openLoginModal();
      }
      return null;
    }
    if (!res.ok) throw new Error('Buy Now failed: ' + res.status);
    return res.json();
  })
  .then(data => {
    if (!data) return null; // 401 path — modal already opened
    if (data.success && data.redirect) {
      window.location.href = data.redirect;
    }
    return data;
  })
  .catch(err => {
    console.error('Buy Now failed:', err);
    return null;
  });

  return promise;
}

/**
 * Show the global toast/confirmation card with product details.
 *
 * @param {string} productName  Product name to display
 * @param {string} productPrice Formatted price to display
 */
function showToast(productName, productPrice) {
  const toast = document.getElementById('toast');
  if (!toast) return;

  // Update product details
  const nameEl = document.getElementById('toastProductName');
  const priceEl = document.getElementById('toastProductPrice');

  if (nameEl && productName) {
    nameEl.textContent = productName;
  }
  if (priceEl && productPrice) {
    priceEl.textContent = productPrice;
  }

  // Reset auto-dismiss timeout
  if (toast.dismissTimeout) {
    clearTimeout(toast.dismissTimeout);
  }

  // Show the toast
  toast.classList.add('show');

  // Wire up close button if not already wired
  if (!toast.continueButtonWired) {
    const continueBtn = document.getElementById('toastContinueBtn');
    if (continueBtn) {
      continueBtn.addEventListener('click', () => {
        toast.classList.remove('show');
      });
    }
    toast.continueButtonWired = true;
  }

  // Auto-dismiss after 8 seconds
  toast.dismissTimeout = setTimeout(() => {
    toast.classList.remove('show');
  }, 8000);
}

/**
 * Retry the pending cart action after a guest successfully logs in.
 * Called by the login modal's load handler (full-page reload flow).
 * Supports both 'addToCart' (default) and 'buyNow' action types.
 * The 10-minute TTL is checked by loadPendingAction().
 */
function retryPendingCartAction() {
    const action = loadPendingAction();

    if (!action) return;

    clearPendingAction();

    if (action.type === 'buyNow') {
        buyNow(action.productId, action.quantity, action.variantId);
        return;
    }

    addToCart(action.productId || action.product_id, action.quantity);
}

window.addToCart              = addToCart;
window.buyNow                 = buyNow;
window.retryPendingCartAction = retryPendingCartAction;
window.clearPendingAction     = clearPendingAction;
