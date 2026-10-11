/**
 * Buyer navbar interactions: wishlist toggle, notifications dropdown, account dropdown
 */

(function () {
    // Close all dropdowns
    function closeAllDropdowns() {
        document.querySelectorAll('[id$="Dropdown"]').forEach(dropdown => {
            if (dropdown.classList.contains('open')) {
                dropdown.classList.remove('open');
                const toggle = document.querySelector(`[aria-controls="${dropdown.id}"], [aria-expanded][aria-haspopup]`);
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // Escape key closes all dropdowns
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAllDropdowns();
        }
    });

    // Helper to open one dropdown and close others
    function toggleDropdownExclusive(toggleBtn, dropdownId) {
        const dropdown = document.getElementById(dropdownId);
        if (!dropdown) return;

        // If already open, close it
        if (dropdown.classList.contains('open')) {
            dropdown.classList.remove('open');
            toggleBtn.setAttribute('aria-expanded', 'false');
        } else {
            // Close all other dropdowns first
            closeAllDropdowns();
            // Then open this one
            dropdown.classList.add('open');
            toggleBtn.setAttribute('aria-expanded', 'true');
        }
    }

    // Account dropdown toggle
    function initAccountDropdown() {
        const btn = document.querySelector('.js-account-toggle');
        const menu = document.getElementById('accountDropdown');
        if (!btn || !menu) return;

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleDropdownExclusive(btn, 'accountDropdown');
        });

        document.addEventListener('click', function (e) {
            if (!btn.contains(e.target) && !menu.contains(e.target)) {
                menu.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // Wishlist toggle button in navbar (simple link, not a product card wishlist)
    function initWishlistToggle() {
        const wishlistBtn = document.querySelector('[data-wishlist-toggle]');
        if (!wishlistBtn) return;

        // Click redirects to wishlist page for navbar button (or login if guest)
        wishlistBtn.addEventListener('click', function (e) {
            e.preventDefault();
            // Check if buyer is authenticated by looking for avatar or account dropdown
            const isAuthenticated = document.querySelector('.js-account-toggle') !== null;
            if (!isAuthenticated) {
                // Guest: trigger login modal
                const loginTrigger = document.querySelector('[data-login-trigger]');
                if (loginTrigger) {
                    loginTrigger.click();
                } else {
                    // Fallback: redirect to login
                    window.location.href = '/buyer/login';
                }
            } else {
                // Authenticated: go to wishlist page
                window.location.href = '/buyer/wishlist';
            }
        });
    }

    // Notifications dropdown
    function initNotifications() {
        const notifBtn = document.getElementById('notificationToggle');
        const notifDropdown = document.getElementById('notificationDropdown');
        const markAllReadBtn = document.getElementById('markAllRead');
        const notifList = document.getElementById('notificationList');

        if (!notifBtn || !notifDropdown) return;

        // Open/close dropdown (exclusive)
        notifBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleDropdownExclusive(notifBtn, 'notificationDropdown');
            if (notifDropdown.classList.contains('open')) {
                loadNotifications();
            }
        });

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!notifBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
                notifDropdown.classList.remove('open');
                notifBtn.setAttribute('aria-expanded', 'false');
            }
        });

        // Mark all as read
        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', function (e) {
                e.preventDefault();
                fetch('/buyer/notifications/mark-all-read', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                    },
                }).then(() => {
                    loadNotifications();
                    updateNotificationBadge();
                }).catch(err => console.error('Error marking notifications as read:', err));
            });
        }

        function loadNotifications() {
            fetch('/buyer/notifications', {
                headers: {
                    'Accept': 'application/json',
                },
            })
                .then(res => res.json())
                .then(data => {
                    if (data.notifications && data.notifications.length > 0) {
                        notifList.innerHTML = data.notifications
                            .map(notif => `
                                <div class="border-b border-line last:border-0 p-3 hover:bg-gray-50 cursor-pointer transition-colors" data-notification-id="${notif.id}">
                                    <p class="text-[12px] font-semibold text-buyer-ink">${notif.title || notif.type}</p>
                                    <p class="text-[11px] text-buyer-ink-soft mt-1">${notif.message}</p>
                                    <p class="text-[10px] text-neutral-400 mt-2">${formatTime(notif.created_at)}</p>
                                </div>
                            `)
                            .join('');

                        // Click to mark as read and navigate
                        notifList.querySelectorAll('[data-notification-id]').forEach(item => {
                            item.addEventListener('click', function () {
                                const notifId = this.dataset.notificationId;
                                markNotificationRead(notifId);
                            });
                        });
                    } else {
                        notifList.innerHTML = `
                            <div class="p-8 text-center">
                                <p class="text-[13px] font-semibold text-buyer-ink">No notifications yet</p>
                                <p class="text-[12px] text-buyer-ink-soft mt-1">You're all caught up!</p>
                            </div>
                        `;
                    }
                })
                .catch(err => console.error('Error loading notifications:', err));
        }

        function markNotificationRead(notifId) {
            fetch(`/buyer/notifications/${notifId}/read`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Content-Type': 'application/json',
                },
            })
                .then(() => {
                    loadNotifications();
                    updateNotificationBadge();
                })
                .catch(err => console.error('Error marking notification as read:', err));
        }

        function updateNotificationBadge() {
            fetch('/buyer/notifications/count', {
                headers: {
                    'Accept': 'application/json',
                },
            })
                .then(res => res.json())
                .then(data => {
                    const badge = notifBtn.querySelector('span');
                    if (badge) {
                        if (data.count > 0) {
                            badge.textContent = Math.min(data.count, 9);
                            badge.style.display = 'flex';
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                })
                .catch(err => console.error('Error updating notification badge:', err));
        }

        function formatTime(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000); // seconds

            if (diff < 60) return 'just now';
            if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
            if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
            return date.toLocaleDateString();
        }
    }

    // Mini cart dropdown
    function initMiniCart() {
        const cartBtn = document.getElementById('cartDropdownToggle');
        const miniCartDropdown = document.getElementById('miniCartDropdown');
        const miniCartItems = document.getElementById('miniCartItems');

        if (!cartBtn || !miniCartDropdown) return;

        cartBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleDropdownExclusive(cartBtn, 'miniCartDropdown');
            if (miniCartDropdown.classList.contains('open')) {
                loadMiniCart();
            }
        });

        document.addEventListener('click', function (e) {
            if (!cartBtn.contains(e.target) && !miniCartDropdown.contains(e.target)) {
                miniCartDropdown.classList.remove('open');
                cartBtn.setAttribute('aria-expanded', 'false');
            }
        });

        function loadMiniCart() {
            fetch('/buyer/cart/mini', {
                headers: {
                    'Accept': 'application/json',
                },
            })
                .then(res => res.json())
                .then(data => {
                    if (data.items && data.items.length > 0) {
                        miniCartItems.innerHTML = data.items
                            .map(item => `
                                <div class="flex gap-3 border-b border-line p-3 hover:bg-gray-50">
                                    ${item.image_url ? `<img src="${item.image_url}" alt="${item.name}" class="h-16 w-16 rounded object-cover bg-gray-100">` : '<div class="h-16 w-16 rounded bg-gray-100"></div>'}
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[12px] font-semibold text-buyer-ink truncate">${item.name}</p>
                                        ${item.variant ? `<p class="text-[10px] text-buyer-ink-soft">${item.variant}</p>` : ''}
                                        <p class="text-[11px] font-semibold text-buyer-ink mt-1">${item.price}</p>
                                        <div class="mt-2 flex items-center gap-2">
                                            <button class="px-2 py-0.5 text-[11px] text-buyer-ink-soft hover:text-buyer-ink" onclick="updateCartQty(${item.id}, ${Math.max(1, item.quantity - 1)})">−</button>
                                            <span class="text-[11px] font-semibold text-buyer-ink">${item.quantity}</span>
                                            <button class="px-2 py-0.5 text-[11px] text-buyer-ink-soft hover:text-buyer-ink" onclick="updateCartQty(${item.id}, ${item.quantity + 1})">+</button>
                                            <button class="ml-auto text-[10px] text-primary-800 hover:text-primary-900 font-semibold" onclick="removeFromCart(${item.id})">Remove</button>
                                        </div>
                                    </div>
                                </div>
                            `)
                            .join('');
                    } else {
                        miniCartItems.innerHTML = `
                            <div class="flex flex-col items-center justify-center h-full gap-2 p-6">
                                <i data-lucide="shopping-bag" width="32" height="32" class="text-buyer-ink-soft"></i>
                                <p class="text-[13px] font-semibold text-buyer-ink">Your cart is empty</p>
                                <p class="text-[12px] text-buyer-ink-soft">Start shopping to add items</p>
                            </div>
                        `;
                        lucide.createIcons();
                    }

                    document.getElementById('miniCartSubtotal').textContent = data.subtotal;
                    document.getElementById('miniCartTotal').textContent = data.total;
                    lucide.createIcons();
                })
                .catch(err => console.error('Error loading mini cart:', err));
        }
    }

    window.updateCartQty = function(itemId, qty) {
        if (qty < 1) {
            window.removeFromCart(itemId);
            return;
        }

        fetch(`/buyer/cart/${itemId}`, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ quantity: qty }),
        })
            .then(res => res.json())
            .then(() => {
                document.getElementById('cartDropdownToggle').click();
                document.getElementById('cartDropdownToggle').click();
                window.updateCartBadge();
            })
            .catch(err => console.error('Error updating cart:', err));
    };

    window.removeFromCart = function(itemId) {
        fetch(`/buyer/cart/${itemId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            },
        })
            .then(res => res.json())
            .then(() => {
                document.getElementById('cartDropdownToggle').click();
                document.getElementById('cartDropdownToggle').click();
                window.updateCartBadge();
            })
            .catch(err => console.error('Error removing from cart:', err));
    };

    window.updateCartBadge = function() {
        fetch('/buyer/cart/summary', {
            headers: {
                'Accept': 'application/json',
            },
        })
            .then(res => res.json())
            .then(data => {
                document.getElementById('cartCount').textContent = data.count;
            });
    };

    // Initialize all on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAccountDropdown();
            initWishlistToggle();
            initNotifications();
            initMiniCart();
        });
    } else {
        initAccountDropdown();
        initWishlistToggle();
        initNotifications();
        initMiniCart();
    }
})();
