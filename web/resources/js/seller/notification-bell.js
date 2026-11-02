// Seller notification bell: refresh the existing role-scoped database alerts.
(function () {
    'use strict';
    const root = document.getElementById('seller-notification-bell');
    if (!root || root.dataset.liveNotificationsBound) return;
    root.dataset.liveNotificationsBound = '1';
    const btn = document.getElementById('sp-bell-btn');
    const dropdown = document.getElementById('sp-notif-dropdown');
    const badge = document.getElementById('sp-bell-badge');
    const list = document.getElementById('sp-notif-list');
    const markAll = root.querySelector('[data-seller-mark-all]');
    if (!btn || !dropdown || !badge || !list || !markAll) return;
    let inFlight = false;
    let sequence = 0;
    let changing = false;
    let stopped = false;
    const node = (tag, className, text = '') => {
        const element = document.createElement(tag);
        element.className = className;
        element.textContent = text;
        return element;
    };
    function render(notifications) {
        const rows = notifications.map(notification => {
            const row = node('li', 'sp-notif-item relative');
            const link = node('a', 'block rounded-lg p-2 no-underline hover:bg-[var(--bg-hover)]');
            link.href = notification.action_url;
            link.append(node('span', 'sp-notif-item__title block', notification.title),
                node('span', 'sp-notif-item__message block', notification.message),
                node('span', 'sp-notif-item__time block', notification.created_at_human || ''));
            row.append(link);
            if (!notification.read) {
                const form = node('form', 'mt-2');
                form.method = 'POST';
                form.action = notification.read_url;
                form.dataset.notificationRead = '';
                const token = node('input', '');
                token.type = 'hidden'; token.name = '_token'; token.value = csrf();
                const button = node('button', 'text-xs font-semibold text-primary-700 underline', 'Mark as read');
                button.type = 'submit';
                form.append(token, button);
                row.append(form);
            } else {
                row.append(node('span', 'text-xs text-[var(--text-muted)]', 'Read'));
            }
            return row;
        });
        if (rows.length) {
            const ul = node('ul', 'm-0 list-none p-0');
            ul.append(...rows);
            list.replaceChildren(ul);
        } else {
            const empty = node('div', 'sp-notif-empty');
            empty.append(node('p', 'sp-notif-empty__title', "You're all caught up"),
                node('p', 'sp-notif-empty__sub', 'Low-stock alerts and store updates will show up here.'));
            list.replaceChildren(empty);
        }
    }
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || root.querySelector('input[name="_token"]')?.value || '';
    async function refresh(force = false) {
        if (stopped || document.hidden || changing || (inFlight && !force)) return;
        const request = ++sequence;
        inFlight = true;
        try {
            const response = await fetch(root.dataset.recentUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if ([401, 403, 419].includes(response.status)) { stopped = true; return; }
            if (!response.ok) return;
            const data = await response.json();
            if (request !== sequence || !Array.isArray(data.notifications)) return;
            const count = Math.max(0, Number(data.unread_count) || 0);
            badge.textContent = count > 9 ? '9+' : String(count);
            badge.hidden = count <= 0;
            markAll.hidden = count <= 0;
            btn.setAttribute('aria-label', count ? `Notifications, ${count} unread` : 'Notifications');
            if (!force && !dropdown.hidden && list.contains(document.activeElement)) return;
            render(data.notifications);
        } catch { /* Keep the existing alerts when a refresh fails. */ }
        finally { if (request === sequence) inFlight = false; }
    }
    function close() {
        dropdown.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
    }
    btn.addEventListener('click', event => {
        event.stopPropagation();
        if (!dropdown.hidden) { close(); return; }
        dropdown.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        refresh(true);
    });
    document.addEventListener('click', event => {
        if (!dropdown.hidden && !root.contains(event.target)) close();
    });
    document.addEventListener('keydown', event => {
        if (!dropdown.hidden && event.key === 'Escape') { close(); btn.focus(); }
    });
    root.addEventListener('submit', async event => {
        const form = event.target;
        if (form !== markAll && !form.hasAttribute('data-notification-read')) return;
        event.preventDefault();
        if (changing) return;
        changing = true;
        sequence++;
        try {
            const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
            if ([401, 403, 419].includes(response.status)) stopped = true;
        } catch { /* Keep unread alerts when marking them read fails. */ }
        finally { changing = false; inFlight = false; refresh(true); }
    });
    window.setInterval(() => refresh(), 10000);
    window.addEventListener('focus', () => refresh());
    document.addEventListener('visibilitychange', () => refresh());
    refresh();
})();
