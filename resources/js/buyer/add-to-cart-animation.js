/**
 * Product-card Add to Cart wiring + brief “Added” button state.
 * Toast confirmation lives in buyer.js; cart icon navigates to /buyer/cart.
 */

(function () {
  const BUTTON_STATE_DURATION = 1500; // ms
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.addEventListener('click', function (event) {
    const button = event.target.closest('.add-to-cart-btn');
    if (!button || button.disabled) return;

    event.preventDefault();
    event.stopPropagation();

    const productId = Number(button.dataset.productId);
    if (!productId || typeof window.addToCart !== 'function') return;

    window.handleAddToCartSimple(button, productId);
  });

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
        setButtonAdded(button, originalHTML, originalClasses);
      })
      .catch(() => {
        button.disabled = false;
      });
  };

  function setButtonAdded(button, originalHTML, originalClasses) {
    if (prefersReducedMotion) {
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
