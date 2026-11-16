// resources/js/buyer/products-load-more.js
// Load More Products button: appends paginated products via AJAX,
// preserves scroll position, filters, and sorting.

document.addEventListener('DOMContentLoaded', () => {
  const loadMoreBtn = document.getElementById('js-load-more-btn');
  const productsGrid = document.querySelector('[data-products-grid]');
  const showingEl = document.getElementById('js-products-showing');
  const totalEl = document.getElementById('js-products-total');
  const status = document.getElementById('product-load-status');
  function announce(message) { if (status) { status.textContent = message; status.hidden = !message; } }

  if (!loadMoreBtn || !productsGrid) {
    return;
  }

  // Track loaded product IDs to prevent duplicates
  const loadedIds = new Set();
  const sessionKey = 'shop_scroll_state';
  const filtersClearedKey = 'shop_filters_cleared';

  // Collect already-loaded product IDs from initial grid
  document.querySelectorAll('[data-products-grid] [data-product-id]').forEach((card) => {
    const id = card.dataset.productId;
    if (id) {
      loadedIds.add(parseInt(id, 10));
    }
  });

  // ── Scroll position management ────────────────────────────────────────

  /**
   * Save scroll position and current filters to sessionStorage.
   * Called on page load and before navigating away.
   */
  function saveScrollState() {
    const state = {
      scrollTop: window.scrollY,
      filters: {
        q: new URLSearchParams(window.location.search).get('q'),
        category: new URLSearchParams(window.location.search).get('category'),
        sort: new URLSearchParams(window.location.search).get('sort'),
        min_price: new URLSearchParams(window.location.search).get('min_price'),
        max_price: new URLSearchParams(window.location.search).get('max_price'),
        on_sale: new URLSearchParams(window.location.search).get('on_sale'),
      },
      timestamp: Date.now(),
    };
    try { sessionStorage.setItem(sessionKey, JSON.stringify(state)); } catch { /* Loading works without browser storage. */ }
  }

  /**
   * Restore scroll position if returning from product details.
   * Only restore if filters match current page.
   */
  function restoreScrollState() {
    try {
      const saved = sessionStorage.getItem(sessionKey);
      if (!saved) return;
      const state = JSON.parse(saved);
      const now = Date.now();

      // Only restore if within 10 minutes and filters match
      if (now - state.timestamp > 600000) {
        sessionStorage.removeItem(sessionKey);
        return;
      }

      const current = {
        q: new URLSearchParams(window.location.search).get('q'),
        category: new URLSearchParams(window.location.search).get('category'),
        sort: new URLSearchParams(window.location.search).get('sort'),
        min_price: new URLSearchParams(window.location.search).get('min_price'),
        max_price: new URLSearchParams(window.location.search).get('max_price'),
        on_sale: new URLSearchParams(window.location.search).get('on_sale'),
      };

      // Check if filters match
      const filtersMatch = Object.keys(state.filters).every(
        (key) => state.filters[key] === current[key]
      );

      if (filtersMatch && state.scrollTop > 0) {
        // Restore after a brief delay to ensure DOM is ready
        setTimeout(() => {
          window.scrollTo(0, state.scrollTop);
        }, 100);
      }
    } catch (e) {
      console.error('Failed to restore scroll state:', e);
    }
  }

  // ── Filter reset handling ─────────────────────────────────────────────

  /**
   * Clear scroll state when filters change.
   * Called by sort/category/price filter changes.
   */
  function clearScrollState() {
    try {
      sessionStorage.removeItem(sessionKey);
      sessionStorage.setItem(filtersClearedKey, 'true');
    } catch { /* Filters remain usable when storage is blocked. */ }
  }

  // Wire up filter links to clear state
  document.querySelectorAll('[data-category-pill]').forEach((link) => {
    link.addEventListener('click', clearScrollState);
  });

  const sortLinks = document.querySelectorAll('[data-sort-menu] a');
  sortLinks.forEach((link) => {
    link.addEventListener('click', clearScrollState);
  });

  // Wire up price filter form submit
  const priceForm = document.querySelector('[data-price-filter-form]');
  if (priceForm) {
    priceForm.addEventListener('submit', clearScrollState);
  }

  // Wire up clear all filters link
  const clearAllLinks = document.querySelectorAll('a[href*="/buyer/products"]');
  clearAllLinks.forEach((link) => {
    const href = link.getAttribute('href');
    if (href && !href.includes('?') && !href.includes('sort=')) {
      link.addEventListener('click', clearScrollState);
    }
  });

  // ── Load More button logic ────────────────────────────────────────────

  let isLoading = false;

  loadMoreBtn.addEventListener('click', async () => {
    if (isLoading) return;

    isLoading = true;
    const nextPage = parseInt(loadMoreBtn.dataset.nextPage, 10);
    const originalText = 'Load more products';

    // Show loading state
    loadMoreBtn.disabled = true;
    loadMoreBtn.textContent = 'Loading...';
    productsGrid.setAttribute('aria-busy', 'true');
    announce('Loading more products...');

    try {
      // Build query params from current URL
      const params = new URLSearchParams(window.location.search);
      params.set('page', nextPage);

      const response = await fetch(`/buyer/products/load-more?${params.toString()}`, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const data = await response.json();

      if (!data.html && data.has_more === false) {
        loadMoreBtn.style.display = 'none';
        announce('You have reached the end of these results.');
        return;
      }
      if (typeof data.html !== 'string' || !data.html) throw new Error('No products returned');

      // Parse returned HTML and filter for duplicates
      const temp = document.createElement('div');
      temp.innerHTML = data.html;

      const newCards = Array.from(temp.querySelectorAll('[data-product-id]'));
      const uniqueCards = newCards.filter((card) => {
        const id = parseInt(card.dataset.productId, 10);
        if (loadedIds.has(id)) {
          return false; // Skip duplicate
        }
        loadedIds.add(id);
        return true;
      });

      if (uniqueCards.length === 0 && data.has_more !== false) throw new Error('No new products returned');

      // Append new cards to grid
      uniqueCards.forEach((card) => {
        productsGrid.appendChild(card);
      });

      // Force grid reflow by triggering a style recalculation
      void productsGrid.offsetHeight;

      // Update counter and next page
      if (showingEl) {
        showingEl.textContent = data.showing;
      }
      if (totalEl) {
        totalEl.textContent = data.total;
      }

      if (data.has_more) loadMoreBtn.dataset.nextPage = data.next_page;
      announce(data.has_more ? 'More products loaded.' : 'You have reached the end of these results.');

      // Hide button if no more products
      if (!data.has_more) {
        loadMoreBtn.style.display = 'none';
      }

      // Re-initialize Lucide icons for newly added cards
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }

      // Re-initialize add-to-cart animations if available
      if (window.initAddToCartAnimations && typeof window.initAddToCartAnimations === 'function') {
        window.initAddToCartAnimations();
      }

      // Save scroll state after successful load
      saveScrollState();

      loadMoreBtn.textContent = originalText;
      loadMoreBtn.disabled = false;
    } catch (error) {
      console.error('Load More failed:', error);

      announce('Could not load more products. Your current results are still here. Select Retry loading to try again.');
      // Keep the same page for a retry and preserve the products already shown.
      loadMoreBtn.textContent = 'Retry loading';
      loadMoreBtn.disabled = false;
    } finally {
      isLoading = false;
      loadMoreBtn.disabled = false;
      productsGrid.setAttribute('aria-busy', 'false');
    }
  });

  // ── Initialization ──────────────────────────────────────────────────

  // Restore scroll position on page load
  restoreScrollState();

  // Save scroll state periodically and on page unload
  window.addEventListener('beforeunload', saveScrollState);
  window.addEventListener('scroll', () => {
    // Debounce saves to avoid excessive writes
    clearTimeout(window.scrollSaveTimer);
    window.scrollSaveTimer = setTimeout(saveScrollState, 500);
  });
});
