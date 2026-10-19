import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/buyer/search.js', import.meta.url), 'utf8');
const compatibility = readFileSync(new URL('../../resources/js/buyer/navbar-search.js', import.meta.url), 'utf8');

function mount({ url = 'http://localhost/buyer/products', count = 1, fetcher, storageBlocked = false } = {}) {
    const navigations = [];
    const requests = [];
    const timers = new Map();
    let nextTimer = 0;
    class Element {
        constructor(tag = 'div') { this.tag = tag; this.children = []; this.attributes = {}; this.dataset = {}; this.style = {}; this.listeners = {}; this.className = ''; this.value = ''; }
        get classList() {
            const update = (name, enabled) => { const names = new Set(this.className.split(/\s+/).filter(Boolean)); if (enabled) names.add(name); else names.delete(name); this.className = [...names].join(' '); };
            return { add: name => update(name, true), remove: name => update(name, false), contains: name => this.className.split(/\s+/).includes(name), toggle: (name, enabled) => update(name, enabled) };
        }
        set id(value) { this.attributes.id = value; }
        get id() { return this.attributes.id; }
        set href(value) { this.attributes.href = value; }
        get href() { return this.attributes.href; }
        setAttribute(name, value) { this.attributes[name] = value; }
        getAttribute(name) { return this.attributes[name] ?? null; }
        removeAttribute(name) { delete this.attributes[name]; }
        appendChild(child) { this.children.push(child); return child; }
        replaceChildren(...children) { this.children = children; }
        contains(target) { return this === target || this.children.some(child => child.contains(target)); }
        querySelectorAll(selector) {
            const match = element => selector.startsWith('.') ? element.classList.contains(selector.slice(1)) : selector.startsWith('#') ? element.id === selector.slice(1) : Object.hasOwn(element.attributes, selector.slice(1, -1));
            return this.children.flatMap(child => [...(match(child) ? [child] : []), ...child.querySelectorAll(selector)]);
        }
        querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
        addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
        dispatch(type, details = {}) {
            const event = { target: this, prevented: false, preventDefault() { this.prevented = true; }, ...details };
            (this.listeners[type] || []).forEach(fn => fn(event));
            if (type === 'click' && this.tag === 'a' && !event.prevented) navigations.push(this.href);
            return event;
        }
        scrollIntoView() {}
    }
    const root = new Element();
    const controls = Array.from({ length: count }, () => {
        const wrap = root.appendChild(new Element()); wrap.className = 'zf-search-wrap';
        const input = wrap.appendChild(new Element('input')); input.className = 'zf-search-input';
        const button = wrap.appendChild(new Element('button')); button.className = 'zf-search-btn';
        const category = wrap.appendChild(new Element('input')); category.id = 'catMenuToggle';
        return { wrap, input, button, category, dropdown: () => wrap.querySelector('[data-search-suggestions]') };
    });
    const ready = [];
    const document = { readyState: 'loading', querySelectorAll: selector => root.querySelectorAll(selector), createElement: tag => new Element(tag), addEventListener(type, fn) { if (type === 'DOMContentLoaded') ready.push(fn); else (this.listeners[type] ||= []).push(fn); }, listeners: {} };
    const context = {
        document, URL, console,
        window: { location: { href: url, assign: destination => navigations.push(destination) } },
        localStorage: { getItem() { if (storageBlocked) throw new Error('Blocked'); return null; }, setItem() { if (storageBlocked) throw new Error('Blocked'); } },
        setTimeout(fn) { timers.set(++nextTimer, fn); return nextTimer; }, clearTimeout(id) { timers.delete(id); },
        fetch: async destination => { requests.push(destination); return fetcher ? fetcher(destination) : { ok: true, json: async () => ({ popular: [{ id: 42, name: 'Black Shirt', price: 'PHP 250.00', image: '/storage/black.jpg' }, { id: 43, name: 'Green Shirt', price: 'PHP 300.00', image: null }] }) }; },
    };
    const execute = code => runInNewContext(code, context);
    execute(source);
    ready.splice(0).forEach(fn => fn());
    document.readyState = 'complete';
    return { ...controls[0], controls, document, navigations, requests, execute, async runTimers() { const callbacks = [...timers.values()]; timers.clear(); await Promise.all(callbacks.map(fn => fn())); } };
}

const filteredUrl = 'http://localhost/buyer/products?category=7&sort=price_desc&seller=9&min_price=0&max_price=500&page=4&reviews_page=3';
test('click and Enter each submit once with the same encoded term and supported filters', () => {
    const destinations = [];
    for (const interaction of ['click', 'Enter']) {
        const ui = mount({ url: filteredUrl });
        ui.input.value = '  linen & silk / small  ';
        if (interaction === 'click') ui.button.dispatch('click');
        else ui.input.dispatch('keydown', { key: 'Enter' });
        assert.equal(ui.navigations.length, 1);
        const destination = new URL(ui.navigations[0], 'http://localhost');
        assert.equal(destination.pathname, '/buyer/products');
        for (const [key, value] of Object.entries({ q: 'linen & silk / small', category: '7', sort: 'price_desc', seller: '9', min_price: '0', max_price: '500', page: '1' })) assert.equal(destination.searchParams.get(key), value);
        assert.equal(destination.searchParams.has('reviews_page'), false);
        destinations.push(ui.navigations[0]);
    }
    assert.equal(destinations[0], destinations[1]);
});

test('compatibility entry and repeated initialization cannot attach duplicate listeners', async () => {
    const ui = mount();
    ui.execute(compatibility.replace("import './search.js';", source));
    ui.execute(source);
    assert.equal(ui.button.listeners.click.length, 1);
    assert.equal(ui.input.listeners.keydown.length, 1);
    assert.equal(ui.input.listeners.input.length, 1);
    ui.input.value = 'shirt'; ui.input.dispatch('input'); await ui.runTimers();
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.wrap.querySelectorAll('[data-search-suggestions]').length, 1);
    ui.button.dispatch('click'); assert.equal(ui.navigations.length, 1);
});

test('empty input navigates safely without a query and keeps supported filters', () => {
    for (const interaction of ['click', 'Enter']) {
        const ui = mount({ url: filteredUrl }); ui.input.value = '   ';
        if (interaction === 'click') ui.button.dispatch('click'); else ui.input.dispatch('keydown', { key: 'Enter' });
        const destination = new URL(ui.navigations[0], 'http://localhost');
        assert.equal(destination.pathname, '/buyer/products');
        assert.equal(destination.searchParams.has('q'), false);
        assert.equal(destination.searchParams.get('category'), '7');
        assert.equal(destination.searchParams.get('page'), '1');
    }
});

test('category-detail search keeps the category and existing query prefills input', () => {
    const ui = mount({ url: 'http://localhost/buyer/categories/17?q=blue&sort=price_asc' });
    assert.equal(ui.input.value, 'blue'); ui.button.dispatch('click');
    const destination = new URL(ui.navigations[0], 'http://localhost');
    assert.equal(destination.searchParams.get('category'), '17');
    assert.equal(destination.searchParams.get('sort'), 'price_asc');
});

test('suggestions use one dropdown, exact product destinations, names and prices', async () => {
    const ui = mount(); ui.input.value = 'shirt'; ui.input.dispatch('input'); await ui.runTimers();
    assert.equal(ui.requests[0], '/buyer/search/suggestions?term=shirt');
    const dropdown = ui.dropdown();
    assert.equal(ui.input.getAttribute('aria-expanded'), 'true');
    assert.equal(dropdown.children.length, 2);
    assert.equal(dropdown.children[0].children[1].children[0].textContent, 'Black Shirt');
    assert.equal(dropdown.children[0].children[1].children[1].textContent, 'PHP 250.00');
    dropdown.children[0].dispatch('click');
    assert.deepEqual(ui.navigations, ['/buyer/products/42']);
});

test('keyboard selection navigates once and Escape/category/outside click close suggestions', async () => {
    const ui = mount(); ui.input.value = 'shirt'; ui.input.dispatch('input'); await ui.runTimers();
    assert.equal(ui.input.dispatch('keydown', { key: 'ArrowDown' }).prevented, true);
    assert.equal(ui.dropdown().children[0].getAttribute('aria-selected'), 'true');
    ui.input.dispatch('keydown', { key: 'Enter' });
    assert.deepEqual(ui.navigations, ['/buyer/products/42']);
    for (const close of [() => ui.input.dispatch('keydown', { key: 'Escape' }), () => ui.category.dispatch('change', { target: { checked: true } }), () => ui.document.listeners.click.forEach(fn => fn({ target: {} }))]) {
        ui.input.dispatch('input'); await ui.runTimers(); close();
        assert.equal(ui.input.getAttribute('aria-expanded'), 'false');
        assert.equal(ui.dropdown().classList.contains('hidden'), true);
    }
});

test('stale network responses cannot overwrite newer suggestions', async () => {
    let resolveOld;
    const ui = mount({ fetcher: url => url.endsWith('old') ? new Promise(resolve => { resolveOld = resolve; }) : Promise.resolve({ ok: true, json: async () => ({ popular: [{ id: 2, name: 'New', price: '20' }] }) }) });
    ui.input.value = 'old'; ui.input.dispatch('input'); const oldRequest = ui.runTimers();
    ui.input.value = 'new'; ui.input.dispatch('input'); await ui.runTimers();
    resolveOld({ ok: true, json: async () => ({ popular: [{ id: 1, name: 'Old', price: '10' }] }) });
    await oldRequest;
    assert.equal(ui.dropdown().children[0].href, '/buyer/products/2');
});

test('clearing input cancels pending suggestions and hides results', async () => {
    const ui = mount(); ui.input.value = 'shirt'; ui.input.dispatch('input');
    ui.input.value = ' '; ui.input.dispatch('input'); await ui.runTimers();
    assert.equal(ui.requests.length, 0); assert.equal(ui.input.getAttribute('aria-expanded'), 'false');
});

test('failed requests close safely and empty results have a readable state', async () => {
    const failed = mount({ fetcher: async () => ({ ok: false }) }); failed.input.value = 'shirt'; failed.input.dispatch('input'); await failed.runTimers();
    assert.equal(failed.input.getAttribute('aria-expanded'), 'false');
    const empty = mount({ fetcher: async () => ({ ok: true, json: async () => ({ popular: [] }) }) }); empty.input.value = 'q'; empty.input.dispatch('input'); await empty.runTimers();
    assert.equal(empty.dropdown().children[0].textContent, 'No results found');
    assert.equal(empty.input.getAttribute('aria-expanded'), 'true');
});

test('multiple search controls initialize independently without viewport assumptions', async () => {
    const ui = mount({ count: 2 });
    for (const [index, control] of ui.controls.entries()) {
        control.input.value = 'shirt ' + index; control.button.dispatch('click');
        assert.equal(control.button.listeners.click.length, 1);
        assert.equal(control.input.getAttribute('aria-controls'), control.dropdown().id);
    }
    assert.equal(ui.navigations.length, 2);
    assert.notEqual(ui.controls[0].dropdown().id, ui.controls[1].dropdown().id);
});

test('unavailable storage and composed Enter do not break normal submission', () => {
    const ui = mount({ storageBlocked: true }); ui.input.value = 'shirt';
    ui.input.dispatch('keydown', { key: 'Enter', isComposing: true }); assert.equal(ui.navigations.length, 0);
    ui.input.dispatch('keydown', { key: 'Enter' }); assert.equal(ui.navigations.length, 1);
});

test('suggestion names render as text rather than executable markup', async () => {
    const ui = mount({ fetcher: async () => ({ ok: true, json: async () => ({ popular: [{ id: 8, name: '<script>alert(1)</script>', price: '10' }] }) }) });
    ui.input.value = 'script'; ui.input.dispatch('input'); await ui.runTimers();
    const name = ui.dropdown().children[0].children[1].children[0];
    assert.equal(name.textContent, '<script>alert(1)</script>'); assert.equal(name.children.length, 0);
});
