// resources/js/buyer/home.js
// Buyer home page behaviour: category carousel tab visuals and cart hooks.
// Catalog data is rendered by Blade from the Product model.

// Tab switching for the category carousel / "Featured / New / Best-selling" tabs
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.js-home-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.js-home-tab').forEach((other) => {
        other.classList.remove('active');
        other.setAttribute('aria-selected', 'false');
      });
      tab.classList.add('active');
      tab.setAttribute('aria-selected', 'true');
    });
  });

  // Add-to-cart from home page product cards (if present) — delegate to buyer.js
  document.querySelectorAll('.js-add-cart').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      const productId = Number(button.dataset.productId);
      if (productId && typeof window.addToCart === 'function') {
        window.addToCart(productId);
      }
    });
  });
});
