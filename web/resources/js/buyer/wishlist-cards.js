// One shared handler for every product heart; navbar remains a normal link.
(function () {
    const configElement = document.getElementById('wishlist-config');
    if (!configElement) return;
    const config = JSON.parse(configElement.textContent);
    const saved = new Set(config.savedIds.map(String));
    const busy = new Set();
    const pendingKey = 'zf_pending_wishlist_action';

    function updateBadge() {
        const badge = document.getElementById('wishlistCount');
        if (badge) {
            badge.textContent = saved.size;
            badge.style.display = saved.size ? 'flex' : 'none';
        }
    }

    function render(productId) {
        document.querySelectorAll('[data-wishlist-toggle]').forEach(button => {
            if (button.dataset.productId !== productId) return;
            const active = saved.has(productId);
            const label = active ? 'Remove from wishlist' : 'Add to wishlist';
            button.setAttribute('aria-pressed', String(active));
            button.setAttribute('aria-label', label);
            button.title = label;
            button.style.color = active ? '#e11d48' : '';
            button.disabled = busy.has(productId);
            button.querySelectorAll('svg, i').forEach(icon => {
                icon.style.fill = active ? 'currentColor' : 'none';
                icon.style.stroke = 'currentColor';
            });
        });
    }

    function setSaved(productId, active) {
        if (active) saved.add(productId);
        else saved.delete(productId);
        render(productId);
        updateBadge();
    }

    function requestLogin(productId) {
        sessionStorage.setItem(pendingKey, JSON.stringify({ productId, ts: Date.now() }));
        if (typeof window.openLoginModal === 'function') window.openLoginModal();
    }

    async function toggle(productId) {
        if (!productId || busy.has(productId)) return;
        if (!config.authenticated) {
            requestLogin(productId);
            return;
        }
        const previous = saved.has(productId);
        busy.add(productId);
        setSaved(productId, !previous);
        try {
            const response = await fetch(config.toggleUrl.replace('PRODUCT_ID', productId), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (response.status === 401) {
                setSaved(productId, previous);
                requestLogin(productId);
                return;
            }
            if (!response.ok) throw new Error('Wishlist request failed');
            const data = await response.json();
            if (!data.success || typeof data.isSaved !== 'boolean') throw new Error('Wishlist update failed');
            setSaved(productId, data.isSaved);
            const badge = document.getElementById('wishlistCount');
            if (badge) {
                badge.textContent = data.count;
                badge.style.display = data.count > 0 ? 'flex' : 'none';
            }
            if (!data.isSaved) {
                document.querySelectorAll('[data-wishlist-item]').forEach(card => {
                    if (card.dataset.wishlistItem === productId) card.remove();
                });
                if (document.querySelector('[data-wishlist-list]') &&
                    !document.querySelector('[data-wishlist-item]')) window.location.reload();
            }
        } catch (error) {
            setSaved(productId, previous);
            window.showToast?.('Could not update wishlist. Please try again.', '', 'Wishlist update failed');
        } finally {
            busy.delete(productId);
            render(productId);
        }
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-wishlist-toggle][data-product-id]');
        if (!button || !/^\d+$/.test(button.dataset.productId)) return;
        event.preventDefault();
        event.stopPropagation();
        toggle(button.dataset.productId);
    }, true);

    function initialize() {
        document.querySelectorAll('[data-wishlist-toggle]').forEach(button => render(button.dataset.productId));
        updateBadge();
    }
    initialize();
    document.addEventListener('htmx:afterSwap', initialize);
    window.addEventListener('pageshow', event => {
        if (event.persisted) window.location.reload();
    });
    window.addEventListener('load', () => {
        initialize(); // Lucide may have replaced the original icon nodes.
        if (!config.authenticated) return;
        const raw = sessionStorage.getItem(pendingKey);
        if (!raw) return;
        sessionStorage.removeItem(pendingKey);
        try {
            const action = JSON.parse(raw);
            if (Date.now() - action.ts < 10 * 60 * 1000 &&
                /^\d+$/.test(action.productId) && !saved.has(String(action.productId))) {
                toggle(String(action.productId));
            }
        } catch { /* Ignore stale or malformed pending actions. */ }
    });
})();
