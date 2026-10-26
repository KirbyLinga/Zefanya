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

    // Initialize all on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAccountDropdown();
            initNotifications();
        });
    } else {
        initAccountDropdown();
        initNotifications();
    }
})();
