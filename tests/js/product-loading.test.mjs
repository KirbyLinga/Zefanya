import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
const source = readFileSync(new URL('../../resources/js/buyer/products-load-more.js', import.meta.url), 'utf8');
function mount(responses, storageBlocked = false) {
    const requests = []; const events = {}; const attributes = {};
    const button = { dataset: { nextPage: '2' }, style: {}, textContent: 'Load more products', addEventListener: (key, fn) => events[key] = fn };
    const status = {}; const cards = [{ dataset: { productId: '1' } }];
    const grid = { appendChild: node => cards.push(node), setAttribute: (key, value) => attributes[key] = value };
    const document = { getElementById: id => id === 'js-load-more-btn' ? button : id === 'product-load-status' ? status : {}, querySelector: selector => selector === '[data-products-grid]' ? grid : null, querySelectorAll: selector => selector === '[data-products-grid] [data-product-id]' ? cards.slice() : [], addEventListener: (_, fn) => fn(), createElement: () => ({ querySelectorAll() { return [...this.innerHTML.matchAll(/data-product-id="(\d+)"/g)].map(match => ({ dataset: { productId: match[1] } })); } }) };
    const sessionStorage = { getItem() { if (storageBlocked) throw new Error('Storage blocked'); return null; }, setItem() { if (storageBlocked) throw new Error('Storage blocked'); }, removeItem() {} };
    runInNewContext(source, { document, sessionStorage, URLSearchParams, setTimeout, clearTimeout, console: { error() {} }, window: { location: { search: '?q=rose&on_sale=1&sort=price_asc&min_price=10' }, addEventListener() {}, scrollY: 0 }, fetch: async url => { requests.push(url); const response = responses.shift(); if (response instanceof Error) throw response; return { ok: true, json: async () => response }; } });
    return { button, status, cards, requests, attributes, click: () => events.click() };
}
test('failed loading preserves results and retries the same filtered page without storage', async () => {
    const ui = mount([new Error('offline'), { html: '<a data-product-id="2"></a>', has_more: true, next_page: 3, showing: 2, total: 4 }], true);
    await ui.click();
    assert.equal(ui.cards.length, 1); assert.equal(ui.button.textContent, 'Retry loading');
    assert.match(ui.status.textContent, /Your current results are still here/);
    assert.equal(ui.button.dataset.nextPage, '2'); assert.equal(ui.attributes['aria-busy'], 'false');
    await ui.click();
    assert.equal(ui.cards.length, 2); assert.equal(ui.button.dataset.nextPage, 3);
    assert.equal(ui.button.textContent, 'Load more products');
    assert.equal(ui.requests[0], ui.requests[1]);
    const url = new URL(ui.requests[1], 'http://localhost');
    assert.equal(url.searchParams.get('page'), '2'); assert.equal(url.searchParams.get('on_sale'), '1');
    assert.equal(url.searchParams.get('sort'), 'price_asc'); assert.equal(url.searchParams.get('min_price'), '10');
});
test('an exhausted result set is announced without a false loading error', async () => {
    const ui = mount([{ html: '', has_more: false }]); await ui.click();
    assert.equal(ui.cards.length, 1); assert.equal(ui.button.style.display, 'none');
    assert.equal(ui.status.textContent, 'You have reached the end of these results.');
    assert.equal(ui.attributes['aria-busy'], 'false');
});
test('duplicate products are not appended when reaching the last page', async () => {
    const ui = mount([{ html: '<a data-product-id="1"></a>', has_more: false }]); await ui.click();
    assert.equal(ui.cards.length, 1); assert.equal(ui.button.style.display, 'none');
    assert.match(ui.status.textContent, /end of these results/);
});
