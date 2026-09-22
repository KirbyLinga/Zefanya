// resources/js/buyer/cart.js
// Buyer cart page behaviour: selection sync, quantity stepper, remove /
// bulk-remove, voucher apply / remove, and "proceed to checkout".
//
// Hook convention: every element this file owns uses a `js-cartpage-*` class or
// a `cartpage-` id prefix, so nothing here can collide with buyer.js
// (.js-cart-toggle, .swatch, .size-opt, #qvQty) or product-show.js (js-pdp-*).
//
// Money is NEVER computed here. Every mutation re-reads the totals from the
// server (App\Services\CartSummary), so this page can only ever display
// server-side math.

document.addEventListener('DOMContentLoaded', () => {
  const data = readPageData();

  if (!data) {
    return;
  }

  /** Ids of the cart lines whose checkbox is ticked (strings, as in the DOM). */
  const selected = new Set(
    $$('.js-cartpage-item-check:not(:disabled)')
      .filter((box) => box.checked)
      .map((box) => box.dataset.itemId)
  );

  /** itemId -> debounce timer for the quantity PATCH. */
  const quantityTimers = new Map();

  let summaryTimer = null;

  // ── Small helpers ───────────────────────────────────────────────────────

  function readPageData() {
    const el = document.getElementById('cartpage-data');

    if (!el) {
      return null;
    }

    try {
      return JSON.parse(el.textContent);
    } catch (error) {
      console.error('cart.js: could not parse #cartpage-data', error);

      return null;
    }
  }

  function $(selector, root = document) {
    return root.querySelector(selector);
  }

  function $$(selector, root = document) {
    return Array.from(root.querySelectorAll(selector));
  }

  function rowFor(id) {
    return $(`.js-cartpage-item-row[data-item-id="${id}"]`);
  }

  /** Rows still on the page, ignoring ones currently animating out. */
  function remainingRows() {
    return $$('.js-cartpage-item-row').filter((row) => row.dataset.removing !== '1');
  }

  /** Fill the __ID__ placeholder produced by route() in the Blade island. */
  function itemUrl(template, id) {
    return template.replace('__ID__', encodeURIComponent(id));
  }

  function withQuery(base, params) {
    const query = params.toString();

    return query === '' ? base : `${base}${base.includes('?') ? '&' : '?'}${query}`;
  }

  function setText(selector, value) {
    const el = $(selector);

    if (el && typeof value === 'string') {
      el.textContent = value;
    }
  }

  function toast(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message);
    }
  }

  function updateBadge(count) {
    const badge = document.getElementById('cartCount');

    if (badge && Number.isInteger(count)) {
      badge.textContent = String(count);
    }
  }

  // ─ Requests ────────────────────────────────────────────────────────────

  /**
   * The selection is sent on every call. A present-but-empty `selected_ids`
   * means "nothing is selected" to the server, while an absent key means
   * "no selection was sent" (every available item). That distinction is what
   * lets the total drop to zero and the checkout button disable.
   */
  function selectionParams(extra = {}) {
    const params = new URLSearchParams();

    Object.entries(extra).forEach(([key, value]) => {
      if (Array.isArray(value)) {
        value.forEach((entry) => params.append(`${key}[]`, entry));
      } else {
        params.set(key, value);
      }
    });

    if (selected.size === 0) {
      params.append('selected_ids[]', '');
    } else {
      selected.forEach((id) => params.append('selected_ids[]', id));
    }

    return params;
  }

  function selectedIdsArray() {
    return Array.from(selected);
  }

  function request(url, options = {}) {
    const method = options.method || 'GET';
    const body = options.body === undefined ? null : options.body;

    const headers = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': data.csrfToken,
    };

    if (body !== null) {
      headers['Content-Type'] = 'application/json';
    }

    return fetch(url, {
      method,
      credentials: 'same-origin',
      headers,
      body: body === null ? undefined : JSON.stringify(body),
    }).then(async (response) => {
      const payload = await response.json().catch(() => ({}));

      if (!response.ok) {
        const error = new Error(payload.error || payload.message || 'Something went wrong.');
        error.status = response.status;
        throw error;
      }

      return payload;
    });
  }
  // ── Summary rendering ───────────────────────────────────────────────────

  function applySummary(payload) {
    const quantity = Number(payload.selected_qty) || 0;

    setText('#cartpage-summary-count', `${quantity} ${quantity === 1 ? 'item' : 'items'}`);
    setText('#cartpage-subtotal', payload.subtotal);
    setText('#cartpage-total', payload.total);
    setText('#cartpage-mobile-total', payload.total);

    const lineCount = Number(payload.available_lines) || 0;

    setText('#cartpage-select-all-count', `(${lineCount} ${lineCount === 1 ? 'item' : 'items'})`);

    // Voucher discount row + applied-code chip
    const discountRow = $('#cartpage-discount-row');

    if (discountRow) {
      discountRow.classList.toggle('hidden', !payload.has_voucher);
    }

    if (payload.has_voucher) {
      setText('#cartpage-discount', payload.discount);
      setText('#cartpage-voucher-chip-label', payload.voucher_label);

      const input = $('#cartpage-voucher-input');

      if (input) {
        input.value = '';
      }
    }

    const chip = $('#cartpage-voucher-chip');

    if (chip) {
      chip.classList.toggle('hidden', !payload.has_voucher);
    }

    // A previously applied voucher can be dropped when the selection changes
    // (expired / below min_spend) — surface the server's own reason.
    if (payload.voucher_notice) {
      showVoucherError(payload.voucher_notice);
    }

    // Refresh each row's line total from the server's numbers.
    if (payload.line_totals) {
      Object.entries(payload.line_totals).forEach(([id, total]) => {
        const row = rowFor(id);
        const cell = row && $('.js-cartpage-line-total', row);

        if (cell && total && typeof total.formatted === 'string') {
          cell.textContent = total.formatted;
        }
      });
    }

    updateBadge(payload.cart_count);
    syncSelectionUi();
  }

  // ─ Selection UI (checkbox tri-state + disabled buttons) ────────────────

  function syncSelectionUi() {
    // Seller group checkboxes mirror their own items.
    $$('.js-cartpage-seller-group').forEach((group) => {
      const boxes = $$('.js-cartpage-item-check:not(:disabled)', group);
      const checked = boxes.filter((box) => box.checked).length;
      const groupBox = $('.js-cartpage-seller-check', group);

      if (groupBox) {
        groupBox.disabled = boxes.length === 0;
        groupBox.checked = boxes.length > 0 && checked === boxes.length;
        groupBox.indeterminate = checked > 0 && checked < boxes.length;
      }
    });

    // Select-all mirrors every tickable item.
    const boxes = $$('.js-cartpage-item-check:not(:disabled)');
    const checked = boxes.filter((box) => box.checked).length;
    const selectAll = document.getElementById('cartpage-select-all');

    if (selectAll) {
      selectAll.disabled = boxes.length === 0;
      selectAll.checked = boxes.length > 0 && checked === boxes.length;
      selectAll.indeterminate = checked > 0 && checked < boxes.length;
    }

    const removeSelected = document.getElementById('cartpage-remove-selected');

    if (removeSelected) {
      removeSelected.disabled = selected.size === 0;
    }

    const canCheckout = selected.size > 0;

    $$('.js-cartpage-checkout-btn').forEach((button) => {
      button.disabled = !canCheckout;
    });
  }

  /** Rebuild the selected set from the DOM, then refresh the totals. */
  function commitSelection(delay = 120) {
    selected.clear();

    $$('.js-cartpage-item-check:not(:disabled)')
      .filter((box) => box.checked)
      .forEach((box) => selected.add(box.dataset.itemId));

    syncSelectionUi();
    refreshSummary(delay);
  }

  function refreshSummary(delay = 0) {
    clearTimeout(summaryTimer);

    summaryTimer = setTimeout(() => {
      request(withQuery(data.summaryUrl, selectionParams()))
        .then(applySummary)
        .catch((error) => {
          console.error('cart.js: summary refresh failed', error);
          toast(error.message);
        });
    }, delay);
  }
// ── Quantity stepper (debounced PATCH) ─────────────────────────────────

  function updateStepperBounds(row, quantity, stock) {
    const down = $('.js-cartpage-qty-down', row);
    const up = $('.js-cartpage-qty-up', row);

    if (down) {
      down.disabled = quantity <= 1;
    }

    if (up) {
      up.disabled = quantity >= stock;
    }
  }

  function stepQuantity(id, delta) {
    const row = rowFor(id);
    const display = row && $('.js-cartpage-qty-display', row);

    if (!row || !display || row.dataset.available !== '1') {
      return;
    }

    const stock = Number.parseInt(row.dataset.stock, 10) || 0;
    const current = Number.parseInt(display.textContent, 10) || 1;

    if (row.dataset.confirmedQuantity === undefined) {
      row.dataset.confirmedQuantity = String(current);
    }

    const next = Math.min(Math.max(current + delta, 1), stock);

    if (next === current) {
      return;
    }

    display.textContent = String(next);
    updateStepperBounds(row, next, stock);

    // Debounce so holding the stepper down sends one request, not many.
    clearTimeout(quantityTimers.get(id));

    quantityTimers.set(id, setTimeout(() => {
      commitQuantity(id, next);
    }, 400));
  }

  function commitQuantity(id, quantity) {
    const row = rowFor(id);

    if (!row) {
      return;
    }

    const confirmed = Number.parseInt(row.dataset.confirmedQuantity, 10) || 1;
    const stock = Number.parseInt(row.dataset.stock, 10) || 0;
    const display = $('.js-cartpage-qty-display', row);

    request(itemUrl(data.updateUrl, id), {
      method: 'PATCH',
      body: { quantity, selected_ids: selectedIdsArray() },
    })
      .then((payload) => {
        const applied = Number(payload.quantity) || quantity;

        row.dataset.confirmedQuantity = String(applied);

        // The server may have capped the quantity at the available stock.
        if (display && applied !== quantity) {
          display.textContent = String(applied);
          updateStepperBounds(row, applied, stock);
        }

        applySummary(payload);
      })
      .catch((error) => {
        // Revert to the last server-confirmed quantity.
        if (display) {
          display.textContent = String(confirmed);
        }

        updateStepperBounds(row, confirmed, stock);
        toast(error.message);
      });
  }

  // ── Remove (single + selected) ────────────────────────────────────────

  function animateOut(row) {
    row.dataset.removing = '1';
    row.style.transition = 'opacity 200ms ease, transform 200ms ease';
    row.style.opacity = '0';
    row.style.transform = 'translateX(-12px)';

    setTimeout(() => row.remove(), 200);
  }

  function showEmptyState() {
    // The populated shell is only rendered when the cart is non-empty, so the
    // cleanest way to reach the empty state is a fresh server render.
    window.location.reload();
  }

  /** Drop now-empty containers and swap to the empty state when nothing is left. */
  function tidyUpAfterRemoval(payload) {
    const left = remainingRows();

    $$('.js-cartpage-seller-group').forEach((group) => {
      if (!left.some((row) => group.contains(row))) {
        group.remove();
      }
    });

    const unavailable = document.getElementById('cartpage-unavailable-group');

    if (unavailable && !left.some((row) => unavailable.contains(row))) {
      unavailable.remove();
    }

    if (payload.is_empty || left.length === 0) {
      showEmptyState();

      return;
    }

    applySummary(payload);
  }

  function removeItem(id) {
    const row = rowFor(id);

    if (!row) {
      return;
    }

    request(withQuery(itemUrl(data.destroyUrl, id), selectionParams()), { method: 'DELETE' })
      .then((payload) => {
        selected.delete(id);
        animateOut(row);
        tidyUpAfterRemoval(payload);
      })
      .catch((error) => toast(error.message));
  }

  function removeSelected() {
    const ids = selectedIdsArray();

    if (ids.length === 0) {
      return;
    }

    const removeButton = document.getElementById('cartpage-remove-selected');

    if (removeButton) {
      removeButton.disabled = true;
    }

    request(withQuery(data.bulkDestroyUrl, selectionParams({ ids })), { method: 'DELETE' })
      .then((payload) => {
        ids.forEach((id) => {
          const row = rowFor(id);

          if (row) {
            animateOut(row);
          }

          selected.delete(id);
        });

        tidyUpAfterRemoval(payload);
      })
      .catch((error) => {
        toast(error.message);
        syncSelectionUi();
      });
  }
// ── Voucher ──────────────────────────────────────────────────────────

  function showVoucherError(message) {
    const el = document.getElementById('cartpage-voucher-error');

    if (!el) {
      toast(message);

      return;
    }

    el.textContent = message;
    el.classList.remove('hidden');
  }

  function clearVoucherError() {
    const el = document.getElementById('cartpage-voucher-error');

    if (el) {
      el.textContent = '';
      el.classList.add('hidden');
    }
  }

  function applyVoucher() {
    const input = document.getElementById('cartpage-voucher-input');
    const button = document.getElementById('cartpage-voucher-apply');

    if (!input) {
      return;
    }

    const code = input.value.trim();

    clearVoucherError();

    if (code === '') {
      showVoucherError('Enter a voucher code.');

      return;
    }

    if (button) {
      button.disabled = true;
    }

    request(data.voucherApplyUrl, {
      method: 'POST',
      body: { code, selected_ids: selectedIdsArray() },
    })
      .then((payload) => {
        input.value = '';
        applySummary(payload);
        toast('Voucher applied');
      })
      .catch((error) => showVoucherError(error.message))
      .finally(() => {
        if (button) {
          button.disabled = false;
        }
      });
  }

  function removeVoucher() {
    clearVoucherError();

    request(withQuery(data.voucherRemoveUrl, selectionParams()), { method: 'DELETE' })
      .then((payload) => {
        applySummary(payload);
        toast('Voucher removed');
      })
      .catch((error) => toast(error.message));
  }

  // ─ Proceed to checkout ───────────────────────────────────────────────

  /**
   * Only the ticked ids are handed to checkout, as `?items[]=…`. The server
   * re-filters them against the buyer's own cart, so a tampered id cannot
   * expose another buyer's line.
   */
  function proceedToCheckout() {
    const ids = selectedIdsArray();

    if (ids.length === 0) {
      toast('Select at least one item to continue.');

      return;
    }

    const params = new URLSearchParams();

    ids.forEach((id) => params.append('items[]', id));

    window.location.href = withQuery(data.checkoutUrl, params);
  }

  // ── Wiring (delegated, so freshly-removed rows stay consistent) ───────

  document.addEventListener('change', (event) => {
    if (event.target.closest('.js-cartpage-item-check')) {
      commitSelection(120);

      return;
    }

    const groupBox = event.target.closest('.js-cartpage-seller-check');

    if (groupBox) {
      const group = groupBox.closest('.js-cartpage-seller-group');

      $$('.js-cartpage-item-check:not(:disabled)', group).forEach((box) => {
        box.checked = groupBox.checked;
      });

      commitSelection(120);

      return;
    }

    const selectAll = event.target.closest('#cartpage-select-all');

    if (selectAll) {
      $$('.js-cartpage-item-check:not(:disabled)').forEach((box) => {
        box.checked = selectAll.checked;
      });

      commitSelection(120);
    }
  });

  document.addEventListener('click', (event) => {
    const down = event.target.closest('.js-cartpage-qty-down');

    if (down) {
      stepQuantity(down.dataset.itemId, -1);

      return;
    }

    const up = event.target.closest('.js-cartpage-qty-up');

    if (up) {
      stepQuantity(up.dataset.itemId, 1);

      return;
    }

    const remove = event.target.closest('.js-cartpage-remove');

    if (remove) {
      removeItem(remove.dataset.itemId);

      return;
    }

    if (event.target.closest('#cartpage-remove-selected')) {
      removeSelected();

      return;
    }

    if (event.target.closest('#cartpage-voucher-apply')) {
      applyVoucher();

      return;
    }

    if (event.target.closest('#cartpage-voucher-remove')) {
      removeVoucher();

      return;
    }

    if (event.target.closest('.js-cartpage-checkout-btn')) {
      proceedToCheckout();
    }
  });

  const voucherInput = document.getElementById('cartpage-voucher-input');

  if (voucherInput) {
    voucherInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        applyVoucher();
      }
    });
  }

  // ── Initial state ────────────────────────────────────────────────────

  $$('.js-cartpage-item-row').forEach((row) => {
    const display = $('.js-cartpage-qty-display', row);

    if (display) {
      row.dataset.confirmedQuantity = display.textContent.trim();
    }
  });

  syncSelectionUi();
});