// Provider-scoped database notifications; server rendering remains usable without JS.
//
// Session-expired banner:
//   When the poll or a bell action returns 401/419, the logistics bell now
//   surfaces the shared "Your session expired. Log in again." banner from
//   resources/js/session-banner.js instead of leaving the user in a silent
//   failure state.
(() => {
    const root = document.getElementById('lg-notification-bell');
    if (!root) return;
    const button = root.querySelector('#lg-bell-btn');
    const panel = root.querySelector('#lg-notif-dropdown');
    const badge = root.querySelector('#lg-bell-badge');
    const list = root.querySelector('#lg-notif-list');
    const markAll = root.querySelector('[data-lg-mark-all]');
    const error = root.querySelector('[data-notification-error]');
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || root.querySelector('[name="_token"]')?.value || '';
    const node = (tag, className, text) => {
        const element = document.createElement(tag);
        element.className = className;
        element.textContent = text;
        return element;
    };
    function setCount(value) {
        const count = Number(value) || 0;
        badge.textContent = count > 9 ? '9+' : String(count);
        badge.hidden = count === 0;
        markAll.hidden = count === 0;
        button.setAttribute('aria-label', count ? `Notifications, ${count} unread` : 'Notifications');
    }
    function render(data) {
        setCount(data.unread_count);
        list.replaceChildren();
        if (!data.notifications.length) {
            const empty = node('div', 'sp-notif-empty', '');
            empty.append(node('p', 'sp-notif-empty__title', "You're all caught up"), node('p', 'sp-notif-empty__sub', 'Hub alerts will show up here.'));
            list.append(empty);
        }
        data.notifications.forEach(notification => {
            const row = node('div', 'sp-notif-item', '');
            const openForm = document.createElement('form');
            openForm.method = 'POST';
            openForm.action = notification.open_url;
            const openToken = document.createElement('input');
            openToken.type = 'hidden'; openToken.name = '_token'; openToken.value = csrf();
            const open = node('button', 'block w-full cursor-pointer text-left', '');
            open.type = 'submit';
            open.append(node('span', 'sp-notif-item__title block', notification.title), node('span', 'sp-notif-item__message block', notification.message), node('span', 'sp-notif-item__time block', notification.created_at_human || ''));
            openForm.append(openToken, open);
            row.append(openForm);
            if (notification.read) {
                row.append(node('span', 'font-sans text-xs text-[var(--text-muted)]', 'Read'));
            } else {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = notification.read_url;
                form.dataset.notificationRead = '';
                const token = document.createElement('input');
                token.type = 'hidden'; token.name = '_token'; token.value = csrf();
                const read = node('button', 'font-sans text-xs font-semibold text-primary-700 hover:underline', 'Mark as read');
                read.type = 'submit';
                form.append(token, read); row.append(form);
            }
            list.append(row);
        });
    }
    let updating = false;
    let sequence = 0;
    async function refresh(force = false) {
        if (updating && !force) return;
        const request = ++sequence;
        try {
            const response = await fetch(root.dataset.recentUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) {
                // Non-ok poll responses are handled by the banner layer when they
                // are 401/419. Other failures are best-effort: keep the last view.
                return;
            }
            const data = await response.json();
            if (request !== sequence) return;
            setCount(data.unread_count);
            if (!force && !panel.hidden && list.contains(document.activeElement)) return;
            render(data);
        } catch { /* Keep server-rendered notifications on a failed refresh. */ }
    }

    // Provider desktops are expected to idle-timeout during a business day.
    // The logistics bell keeps polling while the tab is hidden, so an open
    // logistics tab keeps the session alive forever like the rider bell used to.
    // Add a hidden-tab check so the session can expire when the desktop is
    // not being used, consistent with the rider bell now that it also pauses
    // on hidden tabs.
    document.addEventListener('visibilitychange', () => { if (document.hidden) { return; } refresh(); });
    let logisticsPollTimer = setInterval(() => { if (!document.hidden) refresh(); }, 30000);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { clearInterval(logisticsPollTimer); logisticsPollTimer = 0; return; }
        clearInterval(logisticsPollTimer);
        logisticsPollTimer = setInterval(() => { if (!document.hidden) refresh(); }, 30000);
        refresh();
    });


    function close() { panel.hidden = true; button.setAttribute('aria-expanded', 'false'); }

    button.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        button.setAttribute('aria-expanded', String(!panel.hidden));
        if (!panel.hidden) refresh();
    });

    // In-panel session-expired banner: show if a prior action discovered an
    // expired session, and retrofit fetch so future 401/419 responses raise
    // the same banner.
    if (typeof window.__appSessionBanner !== 'undefined') {
        const banner = window.__appSessionBanner;
        if (typeof banner.expiredSessionSeen === 'function' && banner.expiredSessionSeen()) {
            banner.showBanner();
        }
        if (typeof banner.retrofitFetchForSessionExpiry === 'function') {
            banner.retrofitFetchForSessionExpiry();
        }
    }

    document.addEventListener('click', event => { if (!root.contains(event.target)) close(); });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !panel.hidden) { close(); button.focus(); }
    });
    root.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('[data-notification-read], [data-lg-mark-all]')) return;
        event.preventDefault();
        if (updating) return;
        updating = true; ++sequence; error.hidden = true;
        const submit = form.querySelector('button'); submit.disabled = true;
        try {
            const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
            if (!response.ok) {
                // 401/419 from a bell action are session-expired signals. The
                // retrofitted fetch raises the shared banner; for any other
                // failure, give a concrete in-panel message.
                throw new Error(response.status === 401 || response.status === 419 ? 'Read action failed' : 'Could not update notifications. Please try again.');
            }
            const data = await response.json();
            setCount(data.unread_count);
            await refresh(true);
        } catch (err) {
            if (err && err.message === 'Read action failed') {
                // Session-expired message is handled by the banner layer.
                error.textContent = ''; error.hidden = true;
            } else {
                error.textContent = 'Could not update notifications. Please try again.'; error.hidden = false;
            }
        } finally { updating = false; submit.disabled = false; }
    });

    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    refresh();
    window.setInterval(() => { if (!document.hidden) refresh(); }, 30000);
})();
