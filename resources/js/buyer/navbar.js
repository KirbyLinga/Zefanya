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

        if (!notifBtn || !notifDropdown || !notifList || notifBtn.dataset.liveNotificationsBound) return;
        notifBtn.dataset.liveNotificationsBound = '1';
        let inFlight = false;
        let sequence = 0;
        let stopped = false;
        let changing = false;

        // Open/close dropdown (exclusive)
        notifBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleDropdownExclusive(notifBtn, 'notificationDropdown');
            if (notifDropdown.classList.contains('open')) {
                positionNotifications();
                loadNotifications(true);
            }
        });

        function positionNotifications() {
            notifDropdown.style.transform = '';
            const bounds = notifDropdown.getBoundingClientRect();
            const rightEdge = document.documentElement.clientWidth - 12;
            const shift = bounds.left < 12 ? 12 - bounds.left : (bounds.right > rightEdge ? rightEdge - bounds.right : 0);
            if (shift) notifDropdown.style.transform = `translateX(${shift}px)`;
        }
        window.addEventListener('resize', () => {
            if (notifDropdown.classList.contains('open')) positionNotifications();
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
                changing = true;
                sequence++;
                fetch('/buyer/notifications/mark-all-read', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                    },
                }).then(response => {
                    if (!response.ok) throw new Error('Could not mark notifications as read');
                }).catch(err => console.error('Error marking notifications as read:', err))
                    .finally(() => { changing = false; loadNotifications(true); });
            });
        }

        async function loadNotifications(force = false) {
            if (stopped || document.hidden || changing || (inFlight && !force)) return;
            const request = ++sequence;
            inFlight = true;
            try {
                const response = await fetch('/buyer/notifications', {
                    headers: { Accept: 'application/json' }, cache: 'no-store',
                });
                if ([401, 403, 419].includes(response.status)) { stopped = true; return; }
                if (!response.ok) return;
                const data = await response.json();
                if (request !== sequence || !Array.isArray(data.notifications)) return;
                updateNotificationBadge(data.unread_count);
                if (!force && notifDropdown.classList.contains('open') && notifList.contains(document.activeElement)) return;
                if (data.notifications.length > 0) {
                    notifList.replaceChildren(...data.notifications.map(notificationItem));
                } else {
                    notifList.innerHTML = `<div class="p-8 text-center"><p class="text-[13px] font-semibold text-buyer-ink">No notifications yet</p><p class="text-[12px] text-buyer-ink-soft mt-1">You're all caught up!</p></div>`;
                }
            } catch { /* Keep the last successful view when the network is unavailable. */ }
            finally { if (request === sequence) inFlight = false; }
        }

        window.setInterval(() => loadNotifications(), 30000);
        window.addEventListener('focus', () => loadNotifications());
        document.addEventListener('visibilitychange', () => loadNotifications());
        loadNotifications();

        // Messages can hold seller-written text such as a store name, so they are only ever set as text.
        function notificationItem(notif) {
            const item = document.createElement('div');
            item.className = 'border-b border-line last:border-0 p-3 hover:bg-gray-50 cursor-pointer transition-colors';
            item.dataset.notificationId = notif.id;
            item.append(
                notificationText('text-[12px] font-semibold text-buyer-ink', notif.title || notif.type),
                notificationText('text-[11px] text-buyer-ink-soft mt-1', notif.message),
                notificationText('text-[10px] text-neutral-400 mt-2', formatTime(notif.created_at)),
            );

            // Click to mark as read and navigate
            item.addEventListener('click', function () {
                markNotificationRead(notif.id);
            });

            return item;
        }

        function notificationText(className, text) {
            const line = document.createElement('p');
            line.className = className;
            line.textContent = text;

            return line;
        }

        function markNotificationRead(notifId) {
            changing = true;
            sequence++;
            fetch(`/buyer/notifications/${notifId}/read`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
            })
                .then(response => {
                    if (!response.ok) throw new Error('Could not mark notification as read');
                    return response.json();
                })
                .then(data => {
                    if (data.url) {
                        window.location.assign(data.url);
                        return;
                    }
                    changing = false;
                    loadNotifications(true);
                })
                .catch(err => console.error('Error marking notification as read:', err))
                .finally(() => { changing = false; inFlight = false; });
        }

        function updateNotificationBadge(value) {
            const count = Math.max(0, Number(value) || 0);
            const badge = notifBtn.querySelector('[data-notification-count]');
            if (badge) {
                badge.textContent = count > 9 ? '9+' : String(count);
                badge.style.display = count > 0 ? 'flex' : 'none';
            }
            notifBtn.setAttribute('aria-label', count > 0 ? `Notifications, ${count} unread` : 'Notifications');
            if (markAllReadBtn) markAllReadBtn.hidden = count <= 0;
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
