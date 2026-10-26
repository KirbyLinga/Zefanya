// resources/js/buyer/product-show.js
// PDP-specific behaviour. Uses hooks prefixed js-pdp-* and ids prefixed pdp-
// to avoid ANY collision with buyer.js (.js-cart-toggle, etc.).
// Depends on window.addToCart and window.buyNow exposed by buyer.js.

document.addEventListener('DOMContentLoaded', () => {

  // ── 1. Gallery: thumbnail → main image swap ──────────────────────────────
  const mainImg = document.getElementById('pdp-main-img');
  const thumbBtns = document.querySelectorAll('.js-pdp-thumb');
  const generalImage = mainImg?.getAttribute('src') || '';
  let selectedColorImage = null;

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
  const basePriceText = document.getElementById('pdp-price')?.textContent || '';
  let   baseStock      = Math.max(0, parseInt(addCartBtn?.dataset.stock || '0', 10) || 0);

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

    // One color upload is shared by its size combinations. Color alone can preview it.
    const colorAxis = variantsData.find((variant) => Number.isInteger(variant.color_axis))?.color_axis;
    const colorValue = Number.isInteger(colorAxis) ? selectedValues[colorAxis] : null;
    const imageVariant = variantsData.find((variant) =>
      colorValue && variant.values[colorAxis] === colorValue && variant.images?.length
    );
    const nextImage = imageVariant?.images?.[0] || generalImage;
    if (mainImg && selectedColorImage !== nextImage) {
      selectedColorImage = nextImage;
      mainImg.src = nextImage;
      mainImg.style.opacity = nextImage ? '1' : '0';
      thumbBtns.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.src === nextImage)));
    }

    // Price + stock follow the selected variant.
    const priceEl = document.getElementById('pdp-price');
    if (selectedVariant && priceEl) {
      priceEl.textContent = '₱' + selectedVariant.price.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      const hidden = document.getElementById('pdp-variant-id');
      if (hidden) hidden.value = selectedVariant.available && selectedVariant.stock > 0 ? String(selectedVariant.id) : '';
    }

    if (!selectedVariant) {
      if (priceEl) priceEl.textContent = basePriceText;
      const hidden = document.getElementById('pdp-variant-id');
      if (hidden) hidden.value = '';
    }

    const availability = document.getElementById('pdp-availability');
    if (availability) {
      availability.textContent = selectedVariant
        ? (selectedVariant.available && selectedVariant.stock > 0 ? selectedVariant.stock + ' unit(s) available' : 'Out of Stock')
        : (selectedValues.length && selectedValues.every(value => value !== null) ? 'Out of Stock' : variantsScript.dataset.selectionMessage || 'Select color and size to see availability.');
    }

    // Sold-out styling: a value is sold out when every variant carrying it
    // (under the CURRENT other-axis selection) is unavailable or empty.
    document.querySelectorAll('.js-pdp-variant-value').forEach((btn) => {
      const axis = parseInt(btn.dataset.axisIndex, 10);
      const value = btn.dataset.value;
      const probe = selectedValues.map((v, i) => (i === axis ? value : v));
      const hasStock = variantsData.some((v) =>
        v.available && v.stock > 0
        && probe.every((p, i) => i > axis || p === null || p === v.values[i])
        && v.values[axis] === value
      );
      btn.classList.toggle('soldout', !hasStock);
      btn.disabled = !hasStock;
      btn.setAttribute('aria-disabled', String(!hasStock));
      btn.classList.toggle('selected', selectedValues[axis] === value);
      btn.setAttribute('aria-pressed', String(selectedValues[axis] === value));
    });

    pdpQty = currentStock() > 0 ? Math.max(1, Math.min(pdpQty, currentStock())) : 0;
    updateQtyDisplay();
  }

  document.querySelectorAll('.js-pdp-variant-value').forEach((btn) => {
    btn.addEventListener('click', () => {
      const axis = parseInt(btn.dataset.axisIndex, 10);
      if (btn.disabled) return;
      selectedValues[axis] = btn.dataset.value;
      selectedValues = selectedValues.map((value, i) => i > axis ? null : value);

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


  // ── 4. Quantity stepper ──────────────────────────────────────────────────
  const qtyDisplay = document.getElementById('pdp-qty');
  const qtyUp      = document.getElementById('pdp-qty-up');
  const qtyDown    = document.getElementById('pdp-qty-down');
  let   pdpQty     = 1;
  if (variantMode) updateVariantUi();

  function currentStock() {
    return variantMode ? (selectedVariant?.available ? Math.max(0, selectedVariant.stock) : 0) : baseStock;
  }

  function updateQtyDisplay() {
    const stock = currentStock();
    pdpQty = stock > 0 ? Math.max(1, Math.min(pdpQty, stock)) : 0;
    if (qtyDisplay) {
      qtyDisplay.value = String(pdpQty);
      qtyDisplay.max = String(stock);
      qtyDisplay.disabled = stock <= 0;
    }
    if (qtyDown) qtyDown.disabled = stock <= 0 || pdpQty <= 1;
    if (qtyUp) qtyUp.disabled = stock <= 0 || pdpQty >= stock;
    [addCartBtn, document.getElementById('pdp-buy-now')].forEach(button => {
      if (button) button.disabled = stock <= 0 || button.dataset.busy === '1';
    });
    // Dim the down button at min, up button at max
    if (qtyDown) qtyDown.style.opacity = pdpQty <= 1 ? '0.35' : '1';
    if (qtyUp)   qtyUp.style.opacity   = pdpQty >= currentStock() ? '0.35' : '1';
  }

  function readTypedQuantity() {
    const stock = currentStock();
    const typed = Number(qtyDisplay.value);
    pdpQty = stock > 0 ? Math.max(1, Math.min(Number.isFinite(typed) ? Math.trunc(typed) : 1, stock)) : 0;
    // Leave an empty field editable until blur so replacing a number is easy.
    if (qtyDisplay.value !== '') updateQtyDisplay();
  }
  qtyDisplay?.addEventListener('input', readTypedQuantity);
  qtyDisplay?.addEventListener('change', () => { readTypedQuantity(); updateQtyDisplay(); });
  qtyDisplay?.addEventListener('blur', () => { readTypedQuantity(); updateQtyDisplay(); });

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

  // Add to cart — toast is shown by buyer.js; keep a brief “Added” button state.
  function handleAddToCart(productId, quantity, variantId, button) {
    const BUTTON_STATE_DURATION = 1500;
    button.dataset.busy = '1';
    button.disabled = true;
    const originalHTML = button.innerHTML;
    const originalClasses = button.className;

    window.addToCart(productId, quantity, variantId)
      .then(data => {
        if (!data) {
          delete button.dataset.busy;
          updateQtyDisplay();
          return;
        }

        button.className = originalClasses;
        button.classList.add('bg-buyer-ink', 'text-white', '!border-buyer-ink');
        button.innerHTML = '<i data-lucide="check" width="15" height="15"></i> Added';
        if (window.lucide) {
          window.lucide.createIcons({ scope: button });
        }

        setTimeout(() => {
          button.className = originalClasses;
          button.innerHTML = originalHTML;
          delete button.dataset.busy;
          updateQtyDisplay();
          if (window.lucide) {
            window.lucide.createIcons({ scope: button });
          }
        }, BUTTON_STATE_DURATION);
      })
      .catch(() => {
        delete button.dataset.busy;
          updateQtyDisplay();
      });
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

    buyNowBtn.dataset.busy = '1';
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
        delete buyNowBtn.dataset.busy;
        updateQtyDisplay();
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
