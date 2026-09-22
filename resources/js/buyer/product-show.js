// resources/js/buyer/product-show.js
// PDP-specific behaviour. Uses hooks prefixed js-pdp-* and ids prefixed pdp-
// to avoid ANY collision with buyer.js (.swatch/.size-opt, #qvQty, etc.).
// Depends on window.addToCart and window.toggleChat exposed by buyer.js.

document.addEventListener('DOMContentLoaded', () => {

  // ── 1. Gallery: thumbnail → main image swap ──────────────────────────────
  const mainImg = document.getElementById('pdp-main-img');
  const thumbBtns = document.querySelectorAll('.js-pdp-thumb');

  thumbBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      const src = btn.dataset.src || '';

      // Swap main image
      if (mainImg) {
        mainImg.style.opacity = '0';
        setTimeout(() => {
          mainImg.src = src;
          mainImg.style.opacity = src ? '1' : '0';
        }, 120);
      }

      // Update active ring
      thumbBtns.forEach((b) => {
        b.classList.remove('border-buyer-ink');
        b.classList.add('border-transparent');
        b.setAttribute('aria-pressed', 'false');
      });
      btn.classList.add('border-buyer-ink');
      btn.classList.remove('border-transparent');
      btn.setAttribute('aria-pressed', 'true');
    });
  });

  // ── 2. Colour swatches ───────────────────────────────────────────────────
  // Uses js-pdp-color, NOT .swatch — no conflict with quick-view buyer.js
  const colorLabel = document.getElementById('pdp-selected-color');
  const colorBtns  = document.querySelectorAll('.js-pdp-color');

  colorBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      colorBtns.forEach((b) => {
        b.classList.remove('border-buyer-ink', 'ring-2', 'ring-buyer-ink', 'ring-offset-2');
        b.classList.add('border-transparent');
        b.setAttribute('aria-pressed', 'false');
      });
      btn.classList.add('border-buyer-ink', 'ring-2', 'ring-buyer-ink', 'ring-offset-2');
      btn.classList.remove('border-transparent');
      btn.setAttribute('aria-pressed', 'true');
      if (colorLabel) colorLabel.textContent = btn.dataset.color || '';
    });
  });

  // ── 3. Size buttons ──────────────────────────────────────────────────────
  // Uses js-pdp-size, NOT .size-opt — no conflict with quick-view buyer.js
  const sizeBtns  = document.querySelectorAll('.js-pdp-size');
  const sizeError = document.getElementById('pdp-size-error');
  let   selectedSize = null;

  sizeBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      sizeBtns.forEach((b) => {
        b.classList.remove('selected');
        b.setAttribute('aria-pressed', 'false');
      });
      btn.classList.add('selected');
      btn.setAttribute('aria-pressed', 'true');
      selectedSize = btn.dataset.size;
      if (sizeError) sizeError.classList.add('hidden');
    });
  });

  // ── 4. Quantity stepper ──────────────────────────────────────────────────
  const qtyDisplay = document.getElementById('pdp-qty');
  const qtyUp      = document.getElementById('pdp-qty-up');
  const qtyDown    = document.getElementById('pdp-qty-down');
  const addCartBtn = document.getElementById('pdp-add-cart');
  const stock      = parseInt(addCartBtn?.dataset.stock || '99', 10);
  let   pdpQty     = 1;

  function updateQtyDisplay() {
    if (qtyDisplay) qtyDisplay.textContent = pdpQty;
    // Dim the down button at min, up button at max
    if (qtyDown) qtyDown.style.opacity = pdpQty <= 1   ? '0.35' : '1';
    if (qtyUp)   qtyUp.style.opacity   = pdpQty >= stock ? '0.35' : '1';
  }

  qtyUp?.addEventListener('click', () => {
    if (pdpQty < stock) { pdpQty++; updateQtyDisplay(); }
  });
  qtyDown?.addEventListener('click', () => {
    if (pdpQty > 1) { pdpQty--; updateQtyDisplay(); }
  });

  updateQtyDisplay();

  // ── 5. Add to Cart ───────────────────────────────────────────────────────
  addCartBtn?.addEventListener('click', () => {
    // Size must be selected first
    if (!selectedSize) {
      if (sizeError) {
        sizeError.classList.remove('hidden');
        sizeError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
      return;
    }

    const productId = parseInt(addCartBtn.dataset.productId, 10);
    // TODO: pass selectedSize + selectedColor once cart supports variants
    window.addToCart(productId, pdpQty);
  });

  // ── 6. Express Buy Now ───────────────────────────────────────────────────
  const buyNowBtn = document.getElementById('pdp-buy-now');

  buyNowBtn?.addEventListener('click', () => {
    if (!selectedSize) {
      if (sizeError) {
        sizeError.classList.remove('hidden');
        sizeError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
      return;
    }

    const productId = parseInt(buyNowBtn.dataset.productId, 10);
    // addToCart now returns its promise (Task 8 — backwards-compatible change).
    // On success redirect to checkout; on 401 the login modal opens and after
    // login retryPendingCartAction() runs — the redirect won't fire for guests,
    // which is correct (we can't redirect before they log in).
    const promise = window.addToCart(productId, pdpQty);
    if (promise && typeof promise.then === 'function') {
      promise.then((data) => {
        if (data) {
          // data is truthy only when the cart add succeeded (not a 401)
          window.location.href = '/buyer/checkout';
        }
      });
    }
  });

  // ── 7. Tabs ──────────────────────────────────────────────────────────────
  const tabBtns  = document.querySelectorAll('.js-pdp-tab');
  const panels   = document.querySelectorAll('.pdp-panel');

  function activateTab(btn) {
    tabBtns.forEach((b) => {
      b.classList.remove('selected');
      b.setAttribute('aria-selected', 'false');
    });
    panels.forEach((p) => p.classList.add('hidden'));

    btn.classList.add('selected');
    btn.setAttribute('aria-selected', 'true');
    const target = document.getElementById(btn.getAttribute('aria-controls'));
    target?.classList.remove('hidden');
  }

  tabBtns.forEach((btn) => {
    btn.addEventListener('click', () => activateTab(btn));

    // Keyboard arrow navigation (ARIA tabs pattern)
    btn.addEventListener('keydown', (e) => {
      const all   = Array.from(tabBtns);
      const idx   = all.indexOf(btn);
      if (e.key === 'ArrowRight') { all[(idx + 1) % all.length].focus(); }
      if (e.key === 'ArrowLeft')  { all[(idx - 1 + all.length) % all.length].focus(); }
    });
  });

  // Activate first tab on load
  if (tabBtns[0]) activateTab(tabBtns[0]);

  // ── 8. Related products carousel ─────────────────────────────────────────
  const viewport  = document.getElementById('related-viewport');
  const track     = document.getElementById('related-track');
  const prevBtn   = document.getElementById('related-prev');
  const nextBtn   = document.getElementById('related-next');

  if (viewport && track && prevBtn && nextBtn) {
    let relOffset = 0;

    function cardWidth() {
      const first = track.children[0];
      if (!first) return 0;
      const gap = parseFloat(getComputedStyle(track).gap) || 16;
      return first.getBoundingClientRect().width + gap;
    }

    function maxOffset() {
      return Math.max(0, track.scrollWidth - viewport.clientWidth);
    }

    function slideTo(offset) {
      relOffset = Math.max(0, Math.min(offset, maxOffset()));
      track.style.transform = `translateX(-${relOffset}px)`;
      prevBtn.style.opacity = relOffset <= 0           ? '0.35' : '1';
      nextBtn.style.opacity = relOffset >= maxOffset() ? '0.35' : '1';
    }

    prevBtn.addEventListener('click', () => slideTo(relOffset - cardWidth()));
    nextBtn.addEventListener('click', () => slideTo(relOffset + cardWidth()));

    // Recalculate on resize
    let resizeTimer;
    window.addEventListener('resize', () => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(() => slideTo(relOffset), 150);
    });

    slideTo(0);
  }

  // ── 9. Maximize button (simple new-tab open, no lightbox dependency) ─────
  document.getElementById('pdp-maximize')?.addEventListener('click', () => {
    const src = mainImg?.src;
    if (src) window.open(src, '_blank', 'noopener');
  });

});
