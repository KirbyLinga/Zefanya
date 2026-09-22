// Buyer home: tab visuals, wishlist hearts, and cart / quick-view hooks.
// Catalog data is rendered by Blade from the Product model.

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

document.querySelectorAll('.wish-toggle').forEach((button) => {
  button.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    button.classList.toggle('active');
  });
});

document.querySelectorAll('.js-add-cart').forEach((button) => {
  button.addEventListener('click', (event) => {
    event.preventDefault();
    const productId = Number(button.dataset.productId);
    if (productId && typeof window.addToCart === 'function') {
      window.addToCart(productId);
    }
  });
});

document.querySelectorAll('.js-open-qv').forEach((button) => {
  button.addEventListener('click', () => {
    if (typeof window.openQuickView === 'function') {
      window.openQuickView();
    }
  });
});
