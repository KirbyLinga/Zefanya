/*
 * Seller Products table — row actions (menus, status toggle, delete).
 * Static asset loaded via @push('scripts'); no build step required.
 */
(function () {
    'use strict';

    var csrfToken = (function () {
        var el = document.getElementById('sp-csrf');
        if (el) {
            return el.textContent.trim();
        }
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    })();

    function toast(message) {
        if (typeof window.showToast === 'function') {
            window.showToast(message);
            return;
        }
        var bar = document.createElement('div');
        bar.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 z-[60] px-4 py-2.5 rounded-sm bg-neutral-950 text-white font-sans text-[13px] shadow-lg';
        bar.setAttribute('role', 'status');
        bar.textContent = message;
        document.body.appendChild(bar);
        setTimeout(function () {
            bar.remove();
        }, 3000);
    }

    function refreshIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    /* ---------- Actions dropdown menus ---------- */

    var openMenuId = null;

    function setMenuState(root, open) {
        var toggle = root.querySelector('[data-actions-toggle]');
        var menu = root.querySelector('[data-actions-menu]');
        if (!toggle || !menu) {
            return;
        }
        menu.classList.toggle('hidden', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            openMenuId = root.getAttribute('data-actions-root');
        } else if (openMenuId === root.getAttribute('data-actions-root')) {
            openMenuId = null;
        }
    }

    function closeOpenMenu() {
        if (openMenuId === null) {
            return;
        }
        var root = document.querySelector('[data-actions-root="' + openMenuId + '"]');
        if (root) {
            setMenuState(root, false);
        }
        openMenuId = null;
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-actions-toggle]');
        if (toggle) {
            var root = toggle.closest('[data-actions-root]');
            var wasOpen = toggle.getAttribute('aria-expanded') === 'true';
            closeOpenMenu();
            if (!wasOpen && root) {
                setMenuState(root, true);
            }
            return;
        }

        if (!event.target.closest('[data-actions-menu]')) {
            closeOpenMenu();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeOpenMenu();
        }
    });

    /* ---------- Status updates ---------- */

    var STATUS_COLORS = {
        // Tone classes — styled once in products-index.css (theme-aware).
                // Same swap contract as before; the blade renders sp-tone-active, sp-tone-stock, etc.
        active: { text: 'sp-tone-active', dot: 'sp-dot-active' },
        draft: { text: 'sp-tone-draft', dot: 'sp-dot-draft' },
        inactive: { text: 'sp-tone-inactive', dot: 'sp-dot-inactive' }
    };

    function applyStatus(productId, status, label) {
        var colors = STATUS_COLORS[status] || STATUS_COLORS.draft;
        var badge = document.querySelector('[data-status-badge="' + productId + '"]');
        var dot = document.querySelector('[data-status-dot="' + productId + '"]');
        var text = document.querySelector('[data-status-label="' + productId + '"]');

        if (badge) {
            badge.classList.remove('sp-tone-active', 'sp-tone-draft', 'sp-tone-inactive', 'sp-tone-stock');
            badge.classList.add(colors.text);
        }
        if (dot) {
            dot.classList.remove('sp-dot-active', 'sp-dot-draft', 'sp-dot-inactive', 'sp-dot-stock');
            dot.classList.add(colors.dot);
        }
        if (text) {
            text.textContent = label;
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-status-set]');
        if (!button) {
            return;
        }
        event.preventDefault();
        closeOpenMenu();

        fetch(button.getAttribute('data-url'), {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ status: button.getAttribute('data-to') })
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Request failed');
                }
                return response.json();
            })
            .then(function (data) {
                applyStatus(data.id, data.status, data.label);
                refreshIcons();
                return data;
            })
            .then(function (data) {
                toast(data.message || 'Status updated.');
            })
            .catch(function () {
                toast('Could not update the product status.');
            });
    });

    /* ---------- Delete ---------- */

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-delete]');
        if (!button) {
            return;
        }
        event.preventDefault();
        closeOpenMenu();

        var name = button.getAttribute('data-name') || 'this product';
        if (!window.confirm('Delete "' + name + '"? This cannot be undone.')) {
            return;
        }

        var productId = button.getAttribute('data-delete');
        var url = button.getAttribute('data-url');
        if (!url) {
            var desktopDelete = document.querySelector('[data-actions-root="' + productId + '"] [data-delete]');
            url = desktopDelete ? desktopDelete.getAttribute('data-url') : null;
        }
        if (!url) {
            return;
        }

        fetch(url, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Request failed');
                }
                return response.json();
            })
            .then(function (data) {
                var row = document.querySelector('[data-product-row="' + productId + '"]');
                if (row) {
                    row.remove();
                }
                var card = document.querySelector('[data-product-card="' + productId + '"]');
                if (card) {
                    card.remove();
                }
                toast(data.message || ('"' + name + '" was deleted.'));
            })
            .catch(function () {
                toast('Could not delete the product.');
            });
    });
})();
