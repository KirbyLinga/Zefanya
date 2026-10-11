/**
 * Add-to-cart button state manager and cross-page animation coordinator.
 * Handles button disabled state, "Added" state display, and ensures cart count
 * updates are always from the server (never fabricated client-side).
 */

(function () {
  const BUTTON_STATE_DURATION = 1500; // ms
  const DESKTOP_BREAKPOINT = 768; // px
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /**
   * For non-PDP contexts (product cards, wishlist, etc.) that may add to cart,
   * we provide a helper to manage button state without full animation context.
   */
  window.handleAddToCartSimple = function (button, productId, quantity = 1, variantId = null) {
    if (!button) return;

    button.disabled = true;
    const originalHTML = button.innerHTML;
    const originalClasses = button.className;

    window.addToCart(productId, quantity, variantId)
      .then(data => {
        if (!data) {
          button.disabled = false;
          return;
        }

        // Show toast (existing behavior)
        if (window.innerWidth < DESKTOP_BREAKPOINT) {
          // Already shown by addToCart, but ensure cart link is available
          const toast = document.getElementById('toast');
          if (toast && !toast.querySelector('[data-view-cart]')) {
            const link = document.createElement('a');
            link.href = '/buyer/cart';
            link.textContent = ' View';
            link.style.color = 'inherit';
            link.style.textDecoration = 'underline';
            link.style.marginLeft = '6px';
            link.dataset.viewCart = '1';
            toast.appendChild(link);
          }
        }

        // Set button to "Added" state
        setButtonAdded(button, originalHTML, originalClasses);
      })
      .catch(() => {
        button.disabled = false;
      });
  };

  /**
   * Set button to "Added" state (green checkmark + maroon background).
   */
  function setButtonAdded(button, originalHTML, originalClasses) {
    if (prefersReducedMotion) {
      // Skip animation, just disable temporarily
      setTimeout(() => {
        button.disabled = false;
        button.innerHTML = originalHTML;
        button.className = originalClasses;
        if (window.lucide) window.lucide.createIcons({ scope: button });
      }, BUTTON_STATE_DURATION);
      return;
    }

    button.className = originalClasses;
    button.classList.add('bg-buyer-ink', 'text-white');
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
    }, BUTTON_STATE_DURATION);
  }
})();
