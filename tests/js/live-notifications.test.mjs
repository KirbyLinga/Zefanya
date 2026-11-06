import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const flush = () => new Promise(resolve => setImmediate(resolve));
class Element {
    constructor(tag = 'div') {
        this.tag = tag; this.children = []; this.dataset = {}; this.attributes = {}; this.listeners = {};
        this.style = {}; this.hidden = false; this.textContent = ''; this.htmlWrites = []; this.focused = false;
        const names = new Set();
        this.classList = { add: name => names.add(name), remove: name => names.delete(name), contains: name => names.has(name) };
    }
    addEventListener(name, handler) { this.listeners[name] = handler; }
    setAttribute(name, value) { this.attributes[name] = value; }
    hasAttribute(name) { return name === 'data-notification-read' && 'notificationRead' in this.dataset; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    contains() { return this.focused; }
    focus() {}
    getBoundingClientRect() { return { left: 20, right: 340 }; }
    set innerHTML(value) { this.htmlWrites.push(value); this.children = []; }
    querySelector() { return null; }
}
function mount(role, fetcher) {
    const seller = role === 'seller';
    const root = new Element(), button = new Element('button'), dropdown = new Element(), badge = new Element('span');
    const list = new Element(), markAll = new Element('form');
    dropdown.hidden = true;
    markAll.action = '/seller/notifications/read-all';
    root.dataset.recentUrl = '/seller/notifications/recent';
    const formToken = new Element('input'); formToken.value = 'form-csrf';
    root.querySelector = selector => selector === '[data-seller-mark-all]' ? markAll : formToken;
    button.querySelector = () => badge;
    const ids = seller ? { 'seller-notification-bell': root, 'sp-bell-btn': button, 'sp-notif-dropdown': dropdown, 'sp-bell-badge': badge, 'sp-notif-list': list }
        : { notificationToggle: button, notificationDropdown: dropdown, notificationList: list, markAllRead: markAll };
    const requests = [], intervals = [], events = {}, windowEvents = {};
    const document = {
        readyState: 'complete', hidden: false, activeElement: null, documentElement: { clientWidth: 375 },
        getElementById: id => ids[id] || null, createElement: tag => new Element(tag),
        querySelector: () => ({ content: 'csrf-token' }), querySelectorAll: () => [],
        addEventListener: (name, handler) => { events[name] = handler; },
    };
    const window = {
        location: { assign() {} },
        setInterval: (callback, ms) => { intervals.push({ callback, ms }); },
        addEventListener: (name, callback) => { windowEvents[name] = callback; },
    };
    const source = readFileSync(new URL(`../../resources/js/${role}/${seller ? 'notification-bell' : 'navbar'}.js`, import.meta.url), 'utf8');
    const run = () => runInNewContext(source, { document, window, console, Date, Math,
        fetch: async (url, options = {}) => { requests.push({ url, options }); return fetcher(url, options); } });
    run();
    return { root, button, dropdown, badge, list, markAll, document, requests, intervals, events, windowEvents, run,
        poll: () => intervals[0].callback(), open: () => button.listeners.click({ stopPropagation() {} }) };
}
const response = (data, status = 200) => ({ ok: status === 200, status, json: async () => data });
const alert = (message = 'New delivery update') => ({ id: 'one', title: message, message, created_at: new Date().toISOString(),
    action_url: '/seller/orders/1', read_url: '/seller/notifications/one/read', read: false });
const text = node => [node.textContent, ...node.children.flatMap(child => text(child))].filter(Boolean);

for (const role of ['buyer', 'seller']) {
    test(`${role}: new alerts and a previously empty badge update without opening the bell`, async () => {
        let data = { unread_count: 0, notifications: [] };
        const panel = mount(role, () => response(data));
        await flush();
        assert.equal(panel.intervals[0].ms, 10000);
        assert.equal(panel.markAll.hidden, true);
        const attack = '<img src=x onerror=alert(1)>';
        data = { unread_count: 12, notifications: [alert(attack)] };
        panel.poll(); await flush();
        assert.equal(panel.badge.textContent, '9+');
        assert.equal(panel.button.attributes['aria-label'], 'Notifications, 12 unread');
        assert.equal(panel.markAll.hidden, false);
        assert.ok(text(panel.list).includes(attack));
        assert.ok(!panel.list.htmlWrites.some(html => html.includes(attack)));
        assert.equal(panel.requests.at(-1).options.cache, 'no-store');
        panel.run();
        assert.equal(panel.intervals.length, 1, 'Repeated initialization must not attach another poll');
    });

    test(`${role}: hidden tabs pause and visibility or focus resumes updates`, async () => {
        const panel = mount(role, () => response({ unread_count: 0, notifications: [] }));
        await flush();
        panel.document.hidden = true;
        panel.poll(); await flush();
        assert.equal(panel.requests.length, 1);
        panel.document.hidden = false;
        panel.events.visibilitychange(); await flush();
        assert.equal(panel.requests.length, 2);
        panel.windowEvents.focus(); await flush();
        assert.equal(panel.requests.length, 3);
    });

    test(`${role}: failed refresh keeps alerts and an expired session stops polling`, async () => {
        let status = 200;
        const panel = mount(role, () => response({ unread_count: 1, notifications: [alert()] }, status));
        await flush();
        const previous = panel.list.children[0];
        status = 500; panel.poll(); await flush();
        assert.equal(panel.list.children[0], previous);
        status = 401; panel.poll(); await flush();
        const calls = panel.requests.length;
        panel.poll(); panel.windowEvents.focus(); await flush();
        assert.equal(panel.requests.length, calls);
    });

    test(`${role}: overlapping polls are skipped and a stale reply cannot overwrite a newer open`, async () => {
        let finishOld;
        let calls = 0;
        const panel = mount(role, () => ++calls === 1 ? new Promise(resolve => { finishOld = resolve; })
            : response({ unread_count: 8, notifications: [alert('Newest')] }));
        panel.poll(); await flush();
        assert.equal(calls, 1);
        panel.open(); await flush();
        assert.equal(panel.badge.textContent, '8');
        finishOld(response({ unread_count: 1, notifications: [alert('Stale')] })); await flush();
        assert.equal(panel.badge.textContent, '8');
        assert.ok(text(panel.list).includes('Newest'));
        assert.ok(!text(panel.list).includes('Stale'));
    });

    test(`${role}: automatic updates preserve the notification control with keyboard focus`, async () => {
        let data = { unread_count: 1, notifications: [alert('First')] };
        const panel = mount(role, () => response(data));
        await flush(); panel.open(); await flush();
        const previous = panel.list.children[0];
        panel.list.focused = true;
        data = { unread_count: 2, notifications: [alert('Second')] };
        panel.poll(); await flush();
        assert.equal(panel.badge.textContent, '2');
        assert.equal(panel.list.children[0], previous);
    });
}

test('seller: mark-all uses the existing CSRF-protected action and refreshes without navigation', async () => {
    let data = { unread_count: 1, notifications: [alert()] };
    const panel = mount('seller', (url, options) => {
        if (options.method === 'POST') data = { unread_count: 0, notifications: [{ ...alert(), read: true }] };
        return response(data);
    });
    await flush();
    let prevented = false;
    await panel.root.listeners.submit({ target: panel.markAll, preventDefault() { prevented = true; } });
    await flush();
    const post = panel.requests.find(request => request.options.method === 'POST');
    assert.equal(prevented, true);
    assert.equal(post.options.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.equal(panel.badge.hidden, true);
    assert.ok(text(panel.list).includes('Read'));
});


test('seller: AJAX marking read uses the existing form token when the layout has no CSRF meta tag', async () => {
    const panel = mount('seller', () => response({ unread_count: 0, notifications: [] }));
    await flush();
    panel.document.querySelector = () => null;
    await panel.root.listeners.submit({ target: panel.markAll, preventDefault() {} });
    await flush();
    const post = panel.requests.find(request => request.options.method === 'POST');
    assert.equal(post.options.headers['X-CSRF-TOKEN'], 'form-csrf');
});


test('buyer: the open notification panel remains inside a narrow viewport and repositions on resize', async () => {
    const panel = mount('buyer', () => response({ unread_count: 0, notifications: [] }));
    await flush();
    panel.dropdown.getBoundingClientRect = () => ({ left: -132, right: 188 });
    panel.open();
    assert.equal(panel.dropdown.style.transform, 'translateX(144px)');
    panel.document.documentElement.clientWidth = 1440;
    panel.dropdown.getBoundingClientRect = () => ({ left: 300, right: 620 });
    panel.windowEvents.resize();
    assert.equal(panel.dropdown.style.transform, '');
});
