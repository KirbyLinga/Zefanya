/**
 * Wishlist heart toggle on product cards (buyer catalog pages)
 */

(function () {
    function initWishlistCards() {
        const wishlistButtons = document.querySelectorAll('.wishlist-toggle');

        wishlistButtons.forEach(btn => {
            const productId = btn.dataset.productId;
            const icon = btn.querySelector('.wishlist-icon');

            // Check if this product is already in the wishlist
            checkWishlistStatus(productId, btn, icon);

            // Click handler
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                toggleWishlist(productId, btn, icon);
            });
        });
    }

    function checkWishlistStatus(productId, btn, icon) {
        // This would ideally query the server, but for now we can skip it
        // and let the user's first click determine the state. If you want
        // to pre-populate, add a GET endpoint that returns whether the product
        // is in the user's wishlist.
    }

    function toggleWishlist(productId, btn, icon) {
        const isSaved = btn.dataset.saved === 'true';

        fetch(`/buyer/wishlist/${productId}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            },
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const newState = data.isSaved;
                    btn.dataset.saved = newState;

                    if (newState) {
                        btn.classList.add('text-primary-800', 'bg-white');
                        btn.classList.remove('text-neutral-400', 'bg-white/90');
                        icon.style.fill = 'currentColor'; // Solid heart
                        btn.title = 'Remove from wishlist';
                        btn.setAttribute('aria-label', 'Remove from wishlist');
                    } else {
                        btn.classList.remove('text-primary-800', 'bg-white');
                        btn.classList.add('text-neutral-400', 'bg-white/90');
                        icon.style.fill = 'none'; // Outline heart
                        btn.title = 'Save to wishlist';
                        btn.setAttribute('aria-label', 'Save to wishlist');
                    }

                    // Update wishlist count in navbar
                    updateWishlistBadge(data.count);
                }
            })
            .catch(err => console.error('Error toggling wishlist:', err));
    }

    function updateWishlistBadge(count) {
        const badge = document.getElementById('wishlistCount');
        if (badge) {
            if (count > 0) {
                badge.textContent = Math.min(count, 9);
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWishlistCards);
    } else {
        initWishlistCards();
    }

    // Re-initialize when AJAX content is loaded (e.g., after pagination)
    document.addEventListener('htmx:afterSwap', initWishlistCards);
})();
