// resources/js/buyer/buyer.js
// Shared across every Buyer page (cart add, buy-now, guest pending actions).

function closeAllPanels() {
  document.getElementById('accountDropdown')?.classList.remove('open');
}

function toggleAccount() {
  document.getElementById('accountDropdown')?.classList.toggle('open');
}

// Expose globally so navbar inline handlers and other scripts can call them.
window.toggleAccount  = toggleAccount;
window.closeAllPanels = closeAllPanels;

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
    if (!res.ok) return res.json().then(data => {
      throw new Error(Object.values(data.errors || {}).flat()[0] || data.error || data.message || 'Unable to add this item.');
    });
    return res.json();
  })
  .then(data => {
    if (!data) return null; // 401 path — modal already opened
    if (data.cart_count !== undefined) {
      cartCount = data.cart_count;
      const el = document.getElementById('cartCount');
      if (el) el.textContent = cartCount;
    }
    showToast(data.product_name, data.product_price, data.quantity_warning || 'Added to cart');
    return data;
  })
  .catch(err => {
    showToast(err.message, '', 'Unable to add to cart');
    return null;
  });

  return promise;
}

/**
 * Brief “Added to cart” confirmation. Cart icon in the navbar goes to the
 * cart page; this toast only confirms the add and stays put.
 */
function showToast(productName, productPrice, heading = 'Added to cart') {
  const toast = document.getElementById('toast');
  if (!toast) return;
  const headingEl = toast.querySelector('p');
  if (headingEl) headingEl.textContent = heading;

  const nameEl = document.getElementById('toastProductName');
  const priceEl = document.getElementById('toastProductPrice');

  if (nameEl) {
    nameEl.textContent = productName || 'Item';
  }
  if (priceEl) {
    priceEl.textContent = productPrice || '';
    priceEl.style.display = productPrice ? '' : 'none';
  }

  if (toast.dismissTimeout) {
    clearTimeout(toast.dismissTimeout);
  }

  toast.classList.add('show');

  if (!toast.continueButtonWired) {
    const continueBtn = document.getElementById('toastContinueBtn');
    if (continueBtn) {
      continueBtn.addEventListener('click', () => {
        toast.classList.remove('show');
      });
    }
    toast.continueButtonWired = true;
  }

  toast.dismissTimeout = setTimeout(() => {
    toast.classList.remove('show');
  }, 4000);
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
    if (!res.ok) return res.json().then(data => {
      throw new Error(Object.values(data.errors || {}).flat()[0] || data.error || data.message || 'Unable to buy this item.');
    });
    return res.json();
  })
  .then(data => {
    if (!data) return null; // 401 path — modal already opened
    if (data.cart_count !== undefined) {
      cartCount = data.cart_count;
      const badge = document.getElementById('cartCount');
      if (badge) badge.textContent = cartCount;
    }
    if (data.success && data.redirect) {
      window.location.href = data.redirect;
    }
    return data;
  })
  .catch(err => {
    showToast(err.message, '', 'Unable to continue');
    return null;
  });

  return promise;
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

    addToCart(action.productId || action.product_id, action.quantity, action.variantId);
}

window.addToCart              = addToCart;
window.buyNow                 = buyNow;
window.showToast              = showToast;
window.retryPendingCartAction = retryPendingCartAction;
window.clearPendingAction     = clearPendingAction;

// Shared shop grid/list switch, loaded with the buyer layout.
(function () {
            // Grid and list reuse the same card and its padded text block.
            const productGrid = document.querySelector('[data-products-grid]');
            const gridClasses = ['grid-cols-2', 'sm:grid-cols-3', 'lg:grid-cols-4', 'xl:grid-cols-5'];
            document.querySelectorAll('[data-products-view]').forEach(button => {
                button.addEventListener('click', () => {
                    if (!productGrid) return;
                    const layout = button.dataset.productsView;
                    productGrid.dataset.layout = layout;
                    gridClasses.forEach(name => productGrid.classList.toggle(name, layout === 'grid'));
                    productGrid.classList.toggle('grid-cols-1', layout === 'list');
                    document.querySelectorAll('[data-products-view]').forEach(toggle => {
                        const active = toggle === button;
                        toggle.setAttribute('aria-pressed', String(active));
                        toggle.classList.toggle('bg-primary-800', active);
                        toggle.classList.toggle('text-white', active);
                        toggle.classList.toggle('text-buyer-ink-soft', !active);
                    });
                });
            });

})();

// Category sorting submits one native GET form and retains its price filters.
(function () {
    const form = document.querySelector('[data-category-sort-form]');
    if (!form || form.dataset.sortInitialized) return;
    const toggle = form.querySelector('[data-category-sort-toggle]');
    const menu = form.querySelector('[data-category-sort-menu]');
    if (!toggle || !menu) return;
    form.dataset.sortInitialized = 'true';
    const setOpen = open => {
        menu.classList.toggle('hidden', !open);
        toggle.setAttribute('aria-expanded', String(open));
    };
    toggle.addEventListener('click', () => {
        setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });
    form.addEventListener('change', event => {
        if (event.target.matches('input[name="sort"]')) form.requestSubmit();
    });
    document.addEventListener('click', event => {
        if (!form.contains(event.target)) setOpen(false);
    });
    form.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            setOpen(false);
            toggle.focus();
        }
    });
})();
