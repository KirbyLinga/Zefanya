// resources/js/buyer/product-show.js
// PDP-specific behaviour. Uses hooks prefixed js-pdp-* and ids prefixed pdp-
// to avoid ANY collision with buyer.js (.js-cart-toggle, etc.).
// Depends on window.addToCart and window.buyNow exposed by buyer.js.

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

  // ── 3. REAL variant picker (seller-configured option combinations) ──────
  // Server contract: [data-pdp-variants] JSON = [{id, price, stock, available,
  // values:[...]}] in axis order. A selection must cover every axis before
  // Add to Cart is allowed; the chosen variant's price/stock update the box.
  const variantsScript = document.querySelector('[data-pdp-variants]');
  const variantMode    = variantsScript !== null;
  const addCartBtn     = document.getElementById('pdp-add-cart');
  let   variantsData   = [];
  let   selectedValues = [];
  let   selectedVariant = null;
  let   baseStock      = parseInt(addCartBtn?.dataset.stock || '99', 10);

  if (variantMode) {
    try { variantsData = JSON.parse(variantsScript.textContent || '[]'); } catch { variantsData = []; }
    selectedValues = variantsData.length && variantsData[0].values
      ? variantsData[0].values.map(() => null)
      : [];
  }

  function variantFor(selection) {
    return variantsData.find((v) =>
      v.values.length === selection.length
      && v.values.every((value, i) => selection[i] === value)
    ) || null;
  }

  function updateVariantUi() {
    selectedVariant = selectedValues.every((v) => v !== null) ? variantFor(selectedValues) : null;

    // Per-axis labels.
    selectedValues.forEach((value, i) => {
      const label = document.querySelector('[data-pdp-axis-label="' + i + '"]');
      if (label) label.textContent = value || '';
    });

    // Price + stock follow the selected variant.
    const priceEl = document.getElementById('pdp-price');
    if (selectedVariant && priceEl) {
      priceEl.textContent = '₱' + selectedVariant.price.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      baseStock = selectedVariant.stock;
      const hidden = document.getElementById('pdp-variant-id');
      if (hidden) hidden.value = selectedVariant.available ? String(selectedVariant.id) : '';
    }

    // Sold-out styling: a value is sold out when every variant carrying it
    // (under the CURRENT other-axis selection) is unavailable or empty.
    document.querySelectorAll('.js-pdp-variant-value').forEach((btn) => {
      const axis = parseInt(btn.dataset.axisIndex, 10);
      const value = btn.dataset.value;
      const probe = selectedValues.map((v, i) => (i === axis ? value : v));
      const hasStock = variantsData.some((v) =>
        v.available && v.stock > 0
        && probe.every((p, i) => p === null || p === v.values[i])
        && v.values[axis] === value
      );
      btn.classList.toggle('soldout', !hasStock);
    });

    updateQtyDisplay();
  }

  document.querySelectorAll('.js-pdp-variant-value').forEach((btn) => {
    btn.addEventListener('click', () => {
      const axis = parseInt(btn.dataset.axisIndex, 10);
      selectedValues[axis] = btn.dataset.value;

      btn.closest('[role="group"]').querySelectorAll('.js-pdp-variant-value').forEach((b) => {
        b.classList.remove('selected');
        b.setAttribute('aria-pressed', 'false');
      });
      btn.classList.add('selected');
      btn.setAttribute('aria-pressed', 'true');

      const variantError = document.getElementById('pdp-variant-error');
      if (variantError) variantError.classList.add('hidden');

      updateVariantUi();
    });
  });

  if (variantMode) updateVariantUi();

  // ── 4. Quantity stepper ──────────────────────────────────────────────────
  const qtyDisplay = document.getElementById('pdp-qty');
  const qtyUp      = document.getElementById('pdp-qty-up');
  const qtyDown    = document.getElementById('pdp-qty-down');
  let   pdpQty     = 1;

  function currentStock() {
    return variantMode && selectedVariant ? selectedVariant.stock : baseStock;
  }

  function updateQtyDisplay() {
    if (qtyDisplay) qtyDisplay.textContent = pdpQty;
    // Dim the down button at min, up button at max
    if (qtyDown) qtyDown.style.opacity = pdpQty <= 1 ? '0.35' : '1';
    if (qtyUp)   qtyUp.style.opacity   = pdpQty >= currentStock() ? '0.35' : '1';
  }

  qtyUp?.addEventListener('click', () => {
    if (pdpQty < currentStock()) { pdpQty++; updateQtyDisplay(); }
  });
  qtyDown?.addEventListener('click', () => {
    if (pdpQty > 1) { pdpQty--; updateQtyDisplay(); }
  });

  updateQtyDisplay();

  // ── 5. Add to Cart ───────────────────────────────────────────────────────
  addCartBtn?.addEventListener('click', () => {
    const productId = parseInt(addCartBtn.dataset.productId, 10);

    if (variantMode) {
      const variantError = document.getElementById('pdp-variant-error');

      if (!selectedVariant) {
        if (variantError) {
          variantError.textContent = 'Please choose one option per axis before adding to cart.';
          variantError.classList.remove('hidden');
          variantError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        return;
      }

      if (!selectedVariant.available || selectedVariant.stock <= 0) {
        if (variantError) {
          variantError.textContent = 'This combination is sold out — pick another.';
          variantError.classList.remove('hidden');
        }
        return;
      }

      handleAddToCart(productId, pdpQty, selectedVariant.id, addCartBtn);
      return;
    }

    handleAddToCart(productId, pdpQty, null, addCartBtn);
  });

  // Helper function to handle add-to-cart with animation
  function handleAddToCart(productId, quantity, variantId, button) {
    const ANIMATION_DURATION = 650;
    const BUTTON_STATE_DURATION = 1500;
    const DESKTOP_BREAKPOINT = 768;
    const prefersReducedMotion = window.matchMedia('(prefers-reduce-motion: reduce)').matches;

    // Disable button during animation
    button.disabled = true;
    const originalHTML = button.innerHTML;
    const originalClasses = button.className;

    // Start the add-to-cart request
    window.addToCart(productId, quantity, variantId)
      .then(data => {
        if (!data) {
          // Error or 401 — revert button state
          button.disabled = false;
          return;
        }

        // Get product image for animation
        const mainImg = document.getElementById('pdp-main-img');
        const cartIcon = document.getElementById('cartDropdownToggle');

        if (!mainImg || !cartIcon) {
          // No animation context, just show button state
          setAddedState(button, originalHTML, BUTTON_STATE_DURATION, originalClasses);
          return;
        }

        const isMobile = window.innerWidth < DESKTOP_BREAKPOINT;

        if (isMobile || prefersReducedMotion) {
          // Mobile or reduced motion: just button state
          setAddedState(button, originalHTML, BUTTON_STATE_DURATION, originalClasses);
        } else {
          // Desktop: fly animation + button state
          const imgRect = mainImg.getBoundingClientRect();
          const cartRect = cartIcon.getBoundingClientRect();

          if (imgRect && cartRect) {
            flyToCart(mainImg, imgRect, cartRect);
          }

          // Bounce badge and show button state
          setTimeout(() => {
            bounceBadge();
          }, ANIMATION_DURATION * 0.5);

          setAddedState(button, originalHTML, BUTTON_STATE_DURATION, originalClasses);
        }
      })
      .catch(() => {
        button.disabled = false;
      });
  }

  // Animation helper: fly product image to cart
  function flyToCart(sourceImg, sourceRect, targetRect) {
    const clone = document.createElement('div');
    clone.style.position = 'fixed';
    clone.style.zIndex = '9999';
    clone.style.top = sourceRect.top + 'px';
    clone.style.left = sourceRect.left + 'px';
    clone.style.width = sourceRect.width + 'px';
    clone.style.height = sourceRect.height + 'px';
    clone.style.borderRadius = '8px';
    clone.style.boxShadow = '0 8px 16px rgba(0, 0, 0, 0.2)';
    clone.style.backgroundSize = 'cover';
    clone.style.backgroundPosition = 'center';

    if (sourceImg instanceof HTMLImageElement) {
      clone.style.backgroundImage = `url('${sourceImg.src}')`;
    }

    document.body.appendChild(clone);

    const targetCenterX = targetRect.left + targetRect.width / 2;
    const targetCenterY = targetRect.top + targetRect.height / 2;

    const animation = clone.animate(
      [
        { transform: 'translate(0, 0) scale(1)', opacity: 1 },
        {
          transform: `translate(${(targetCenterX - sourceRect.left - sourceRect.width / 2) / 2}px, ${(targetCenterY - sourceRect.top - sourceRect.height / 2) / 2 - 60}px) scale(0.6)`,
          opacity: 0.8,
        },
        {
          transform: `translate(${targetCenterX - sourceRect.left - sourceRect.width / 2}px, ${targetCenterY - sourceRect.top - sourceRect.height / 2}px) scale(0.2)`,
          opacity: 0,
        },
      ],
      {
        duration: 650,
        easing: 'cubic-bezier(0.25, 0.46, 0.45, 0.94)',
      }
    );

    animation.onfinish = () => {
      clone.remove();
    };
  }

  // Animation helper: bounce cart badge
  function bounceBadge() {
    const badge = document.getElementById('cartCount');
    if (!badge) return;

    badge.animate(
      [
        { transform: 'scale(1)' },
        { transform: 'scale(1.3)', offset: 0.5 },
        { transform: 'scale(1)' },
      ],
      {
        duration: 400,
        easing: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
      }
    );
  }

  // Helper: set button to "Added" state
  function setAddedState(button, originalHTML, duration, originalClasses) {
    button.className = originalClasses;
    button.classList.add('bg-buyer-ink', 'text-white', '!border-buyer-ink');
    button.classList.remove('border', 'bg-transparent', 'text-buyer-ink', 'hover:bg-buyer-ink', 'hover:text-white');

    button.innerHTML = '<i data-lucide="check" width="15" height="15"></i> Added';

    if (window.lucide) {
      window.lucide.createIcons({ scope: button });
    }

    setTimeout(() => {
      button.className = originalClasses;
      button.innerHTML = originalHTML;
      button.disabled = false;

      if (window.lucide) {
        window.lucide.createIcons({ scope: button });
      }
    }, duration);
  }

  // ── 6. Checkout (Buy Now) — skips the cart, checks out THIS item only ────
  const buyNowBtn = document.getElementById('pdp-buy-now');

  buyNowBtn?.addEventListener('click', () => {
    const productId = parseInt(buyNowBtn.dataset.productId, 10);
    const variantError = document.getElementById('pdp-variant-error');
    let variantId = null;

    if (variantMode) {
      if (!selectedVariant || !selectedVariant.available || selectedVariant.stock <= 0) {
        if (variantError) {
          variantError.textContent = selectedVariant
            ? 'This combination is sold out — pick another.'
            : 'Please choose one option per axis first.';
          variantError.classList.remove('hidden');
          variantError.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        return;
      }
      variantId = selectedVariant.id;
    }

    buyNowBtn.disabled = true;

    const promise = window.buyNow(productId, pdpQty, variantId);

    if (promise && typeof promise.then === 'function') {
      promise.then((data) => {
        if (data && data.error) {
          if (variantError) {
            variantError.textContent = data.error;
            variantError.classList.remove('hidden');
          }
        }
      }).finally(() => {
        buyNowBtn.disabled = false;
      });
    }
  });

  // ── 7. Related products carousel ─────────────────────────────────────────
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

  // ── 8. Maximize button (simple new-tab open, no lightbox dependency) ─────
  document.getElementById('pdp-maximize')?.addEventListener('click', () => {
    const src = mainImg?.src;
    if (src) window.open(src, '_blank', 'noopener');
  });

  // ── 9. Chat with Seller ─────────────────────────────────────────────────
  const chatBtn = document.getElementById('pdp-chat-seller');
  if (chatBtn) {
    chatBtn.addEventListener('click', async () => {
      const sellerId = chatBtn.dataset.sellerId;
      const productId = chatBtn.dataset.productId;

      try {
        const response = await fetch('{{ route("buyer.messages.get-or-create") }}', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
          },
          body: JSON.stringify({
            seller_id: sellerId,
            product_id: productId,
          }),
        });

        if (response.ok) {
          const data = await response.json();
          window.location.href = `/buyer/messages/${data.conversation_id}`;
        } else {
          alert('Failed to start conversation');
        }
      } catch (error) {
        console.error('Error starting conversation:', error);
        alert('Error starting conversation');
      }
    });
  }

});
