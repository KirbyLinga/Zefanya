import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/buyer/buyer.js', import.meta.url), 'utf8');
function element(dataset = {}, classes = []) {
  const listeners = {}, attributes = {}, tokens = new Set(classes);
  return { dataset, attributes, listeners, focused: false,
    classList: { toggle(name, on) { if (on) tokens.add(name); else tokens.delete(name); }, contains: name => tokens.has(name) },
    addEventListener(name, fn) { (listeners[name] ??= []).push(fn); },
    emit(name, event = {}) { for (const fn of listeners[name] ?? []) fn(event); },
    setAttribute(name, value) { attributes[name] = value; },
    getAttribute(name) { return attributes[name]; },
    focus() { this.focused = true; },
  };
}
function mount(present = true) {
  const grid = element({ layout: 'grid' }, ['grid-cols-2', 'sm:grid-cols-3', 'lg:grid-cols-4', 'xl:grid-cols-5']);
  const products = [11, 22, 33]; grid.children = products;
  const buttons = ['grid', 'list'].map(layout => element({ productsView: layout }));
  const form = element(), toggle = element(), menu = element({}, ['hidden']);
  toggle.setAttribute('aria-expanded', 'false');
  form.querySelector = selector => selector.includes('toggle') ? toggle : menu;
  form.contains = target => [form, toggle, menu].includes(target);
  form.action = '/buyer/categories/7';
  form.filters = { min_price: '100', max_price: '300', sort: 'price_asc' };
  form.submissions = [];
  form.requestSubmit = () => form.submissions.push({ action: form.action, ...form.filters });
  const document = element();
  document.getElementById = () => null;
  document.querySelector = selector => !present ? null : selector === '[data-products-grid]' ? grid : selector === '[data-category-sort-form]' ? form : null;
  document.querySelectorAll = selector => present && selector === '[data-products-view]' ? buttons : [];
  const window = { location: { href: '/buyer/categories/7?sort=price_asc&min_price=100&max_price=300' } };
  runInNewContext(source, { document, window });
  return { grid, buttons, form, toggle, menu, document, window, products };
}

test('list and grid switch responsive classes and active states without changing products or URL', () => {
  const { grid, buttons, products, window, form } = mount();
  const url = window.location.href;
  buttons[1].emit('click');
  assert.equal(grid.dataset.layout, 'list');
  assert.equal(grid.classList.contains('grid-cols-1'), true);
  for (const name of ['grid-cols-2', 'sm:grid-cols-3', 'lg:grid-cols-4', 'xl:grid-cols-5']) assert.equal(grid.classList.contains(name), false);
  assert.equal(buttons[1].attributes['aria-pressed'], 'true');
  assert.equal(buttons[0].attributes['aria-pressed'], 'false');
  assert.equal(buttons[1].classList.contains('bg-primary-800'), true);
  buttons[0].emit('click');
  assert.equal(grid.dataset.layout, 'grid');
  assert.equal(grid.classList.contains('grid-cols-1'), false);
  assert.equal(grid.classList.contains('sm:grid-cols-3'), true);
  assert.equal(buttons[0].attributes['aria-pressed'], 'true');
  assert.equal(buttons[1].attributes['aria-pressed'], 'false');
  assert.strictEqual(grid.children, products);
  assert.equal(window.location.href, url);
  assert.deepEqual(form.submissions, []);
});

test('sort click opens and closes the existing menu with accessible state', () => {
  const { toggle, menu } = mount();
  toggle.emit('click');
  assert.equal(toggle.attributes['aria-expanded'], 'true');
  assert.equal(menu.classList.contains('hidden'), false);
  toggle.emit('click');
  assert.equal(toggle.attributes['aria-expanded'], 'false');
  assert.equal(menu.classList.contains('hidden'), true);
});

test('Escape closes sorting and returns keyboard focus to the toggle', () => {
  const { form, toggle, menu } = mount();
  toggle.emit('click');
  form.emit('keydown', { key: 'Escape' });
  assert.equal(menu.classList.contains('hidden'), true);
  assert.equal(toggle.focused, true);
  assert.equal(toggle.attributes['aria-expanded'], 'false');
  assert.equal(form.submissions.length, 0);
});

test('radio change submits exactly once with category and supported filters', () => {
  const { form } = mount();
  form.emit('change', { target: { matches: selector => selector === 'input[name="sort"]' } });
  assert.deepEqual(form.submissions, [{ action: '/buyer/categories/7', sort: 'price_asc', min_price: '100', max_price: '300' }]);
  form.emit('change', { target: { matches: () => false } });
  assert.equal(form.submissions.length, 1);
});

test('outside clicks close the menu while inside clicks preserve it', () => {
  const { toggle, menu, document } = mount();
  toggle.emit('click');
  document.emit('click', { target: menu });
  assert.equal(menu.classList.contains('hidden'), false);
  document.emit('click', { target: {} });
  assert.equal(menu.classList.contains('hidden'), true);
});

test('other buyer pages without category controls remain safe and retain cart APIs', () => {
  const { window } = mount(false);
  assert.equal(typeof window.addToCart, 'function');
  assert.equal(typeof window.buyNow, 'function');
});
