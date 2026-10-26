// Rider notification bell — dropdown behaviour + lightweight polling.
// No framework and no WebSocket: the server-rendered dropdown is refreshed
// from the rider's own JSON endpoint every 30 seconds.
//// Rider notification bell — dropdown behaviour + lightweight polling.
// No framework and no WebSocket: the server-rendered dropdown is refreshed
// from the rider's own JSON endpoint every 30 seconds.
(function () {
    'use strict';

    var root = document.getElementById('rider-notification-bell');
    if (!root) { return; }

    var btn      = document.getElementById('rider-bell-btn');
    var dropdown = document.getElementById('rider-notif-dropdown');
    var badge    = document.getElementById('rider-bell-badge');
    var list     = document.getElementById('rider-notif-list');
    var markAll  = root.querySelector('[data-rider-mark-all]');
    var recentUrl = root.getAttribute('data-recent-url');

    // If the layout sets the legacy bell endpoint, prefer the session-based
    // poll so the session is extended while the tab is open.
    if (recentUrl && recentUrl.endsWith('/api/rider/recent')) {
        recentUrl = recentUrl.replace('/api/rider/recent', '/rider/notifications/recent');
    }
    if (!recentUrl) { return; }

    if (!btn || !dropdown || !badge || !list) { return; }

    var POLL_MS = 30000;

    function isOpen() {
        return !dropdown.hidden;
    }

    function open() {
        dropdown.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        refresh();
    }

    function close() {
        dropdown.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) { return meta.content; }

        var input = root.querySelector('input[name="_token"]');
        return input ? input.value : '';
    }

    function setBadge(count) {
        var unread = Number(count) || 0;

        badge.textContent = unread > 9 ? '9+' : String(unread);
        badge.hidden = unread <= 0;
        btn.setAttribute('aria-label', unread > 0 ? 'Notifications, ' + unread + ' unread' : 'Notifications');

        if (markAll) {
            markAll.hidden = unread <= 0;
        }
    }

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text) { node.textContent = text; }
        return node;
    }

    function renderRow(notification) {
        var item = element('li', 'sp-notif-item');
        item.setAttribute('role', 'listitem');

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = notification.open_url;

        var token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = csrfToken();
        form.appendChild(token);

        var button = document.createElement('button');
        button.type = 'submit';
        button.className = 'block w-full cursor-pointer border-0 bg-transparent p-0 text-left';

        var title = element('span', 'sp-notif-item__title flex items-center gap-2');

        if (!notification.read) {
            var dot = document.createElement('span');
            dot.setAttribute('aria-hidden', 'true');
            dot.style.cssText = 'display:inline-block;width:6px;height:6px;flex-shrink:0;border-radius:999px;background:var(--color-primary-600);';
            title.appendChild(dot);
        }

        title.appendChild(document.createTextNode(notification.title || 'Notification'));
        button.appendChild(title);

        if (notification.message) {
            button.appendChild(element('span', 'sp-notif-item__message block', notification.message));
        }

        button.appendChild(element('span', 'sp-notif-item__time block', notification.created_at_human || ''));
        form.appendChild(button);
        item.appendChild(form);

        return item;
    }

    function renderList(notifications) {
        list.replaceChildren();

        if (!notifications || notifications.length === 0) {
            var empty = element('div', 'sp-notif-empty');
            var icon = document.createElement('i');
            icon.setAttribute('data-lucide', 'bell-off');
            icon.className = 'h-[28px] w-[28px] text-[var(--icon-soft)]';
            icon.setAttribute('aria-hidden', 'true');
            empty.appendChild(icon);
            empty.appendChild(element('p', 'sp-notif-empty__title', "You're all caught up"));
            empty.appendChild(element('p', 'sp-notif-empty__sub', 'New assignments and account updates will show up here.'));
            list.appendChild(empty);
        } else {
            var ul = element('ul', 'm-0 list-none p-0');
            ul.setAttribute('role', 'list');

            notifications.forEach(function (notification) {
                ul.appendChild(renderRow(notification));
            });

            list.appendChild(ul);
        }

        // The server-rendered icons are replaced with the list; redraw any
        // lucide placeholders that were just created.
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }

    function refresh() {
        if (!recentUrl || typeof window.fetch !== 'function') { return; }

        window.fetch(recentUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (payload) {
            if (!payload) { return; }

            setBadge(payload.unread_count);

            // Do not replace the list while the rider is interacting with it.
            var focused = document.activeElement;
            if (isOpen() && focused && list.contains(focused)) { return; }

            renderList(payload.notifications);
        }).catch(function (err) {
            // Retry once on a 419 / session-expired-like failure so an in-flight
            // poll does not silently hide the dropdown after a CSRF reload.
            if (err && err.status >= 400 && err.status < 500) {
                return window.fetch(recentUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' }
                }).then(function (r) { return r.ok ? r.json() : null; }).then(function (p) {
                    if (p) { setBadge(p.unread_count); renderList(p.notifications); }
                });
            }
        });
    }

    btn.addEventListener('click', function (event) {
        event.stopPropagation();
        if (isOpen()) {
            close();
        } else {
            open();
        }
    });

    document.addEventListener('click', function (event) {
        if (isOpen() && !dropdown.contains(event.target) && !btn.contains(event.target)) {
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (isOpen() && (event.key === 'Escape' || event.key === 'Esc')) {
            close();
            btn.focus();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { return; }

        refresh();
    });

    refresh();
    var pollTimer = window.setInterval(refresh, POLL_MS);

    // Rider browsers are often left open on the dashboard between shifts.
    // The bell's 30s polling extends the session on every tick, so an open
    // tab never idle-timeouts. Only poll when the tab is foregrounded so the
    // session can expire after the configured idle lifetime when the rider is
    // not actively using the app.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            window.clearInterval(pollTimer);
            pollTimer = 0;
            return;
        }

        window.clearInterval(pollTimer);
        pollTimer = window.setInterval(refresh, POLL_MS);
        refresh();
    });
})();
