import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/buyer/navbar.js', import.meta.url), 'utf8');
const attack = '<img src=x onerror="alert(1)">';

// innerHTML is the unsafe path, so reading what was written to it shows whether a message reached it.
class Element {
    constructor(tag = 'div') {
        this.tag = tag;
        this.children = [];
        this.dataset = {};
        this.listeners = {};
        this.textContent = '';
        this.htmlWrites = [];
        this.style = {};
        this.classList = {
            names: new Set(),
            add: name => this.classList.names.add(name),
            remove: name => this.classList.names.delete(name),
            contains: name => this.classList.names.has(name),
        };
    }

    set innerHTML(value) {
        this.htmlWrites.push(value);
    }

    addEventListener(type, handler) {
        this.listeners[type] = handler;
    }

    append(...nodes) {
        this.children.push(...nodes);
    }

    replaceChildren(...nodes) {
        this.children = nodes;
    }

    contains() {
        return false;
    }

    setAttribute() {}

    getBoundingClientRect() { return { left: 20, right: 340 }; }

    querySelector() {
        return null;
    }
}

async function openDropdown(notifications) {
    const elements = {
        notificationToggle: new Element('button'),
        notificationDropdown: new Element(),
        notificationList: new Element(),
    };
    const requests = [];
    const json = body => Promise.resolve({ ok: true, json: () => Promise.resolve(body) });

    runInNewContext(source, {
        console,
        Date,
        Math,
        document: {
            readyState: 'complete',
            documentElement: { clientWidth: 375 },
            addEventListener() {},
            querySelector: () => ({ content: 'token' }),
            querySelectorAll: () => [],
            getElementById: id => elements[id] ?? null,
            createElement: tag => new Element(tag),
        },
        fetch: (url, options = {}) => {
            requests.push({ url, method: options.method ?? 'GET' });

            return url === '/buyer/notifications' ? json({ notifications }) : json({ url: null, count: 0 });
        },
        window: { location: { assign() {} }, setInterval() {}, addEventListener() {} },
    });

    elements.notificationToggle.listeners.click({ stopPropagation() {} });
    await new Promise(resolve => setTimeout(resolve));

    return { list: elements.notificationList, requests };
}

const notification = {
    id: 'abc-123',
    title: attack,
    message: `${attack} cancelled their part of your order.`,
    created_at: new Date().toISOString(),
};

test('seller-written text is shown as plain text and never parsed as HTML', async () => {
    const { list } = await openDropdown([notification]);

    assert.deepEqual(list.htmlWrites, []);
    assert.equal(list.children.length, 1);

    const [title, message] = list.children[0].children;
    assert.equal(title.textContent, attack);
    assert.equal(message.textContent, notification.message);
});

test('a notification without a title falls back to its type', async () => {
    const { list } = await openDropdown([{ ...notification, title: null, type: 'order_update' }]);

    assert.equal(list.children[0].children[0].textContent, 'order_update');
});

test('clicking a notification still marks it as read', async () => {
    const { list, requests } = await openDropdown([notification]);

    list.children[0].listeners.click();

    assert.equal(list.children[0].dataset.notificationId, 'abc-123');
    assert.ok(requests.some(({ url, method }) => url === '/buyer/notifications/abc-123/read' && method === 'POST'));
});

test('an empty list still shows the empty message', async () => {
    const { list } = await openDropdown([]);

    assert.equal(list.htmlWrites.length, 1);
    assert.match(list.htmlWrites[0], /No notifications yet/);
});
