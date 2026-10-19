import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/buyer/product-show.js', import.meta.url), 'utf8');
function mount(variantMode = true) {
  const element = (dataset = {}) => ({ dataset, textContent: '', style: {}, disabled: false, attributes: {},
    classList: { add() {}, remove() {}, toggle() {} },
    setAttribute(name, value) { this.attributes[name] = value; },
    getAttribute(name) { return name === 'src' ? '/cover.jpg' : this.attributes[name]; },
    addEventListener(name, handler) { this[name] = handler; },
    scrollIntoView() {},
  });
  const rows = [
    { id: 1, values: ['Black', 'S'], price: 299, stock: 4, available: true, color_axis: 0, images: ['/black.jpg'] },
    { id: 2, values: ['Black', 'M'], price: 349, stock: 2, available: true, color_axis: 0, images: ['/size-override.jpg'] },
    { id: 3, values: ['Green', 'M'], price: 399, stock: 0, available: true, color_axis: 0, images: ['/green.jpg'] },
  ];
  const ids = Object.fromEntries(['pdp-main-img', 'pdp-price', 'pdp-availability', 'pdp-variant-id', 'pdp-add-cart', 'pdp-buy-now', 'pdp-qty', 'pdp-qty-up', 'pdp-qty-down', 'pdp-variant-error'].map(id => [id, element()]));
  ids['pdp-add-cart'].dataset = { stock: '17', productId: '14' };
  ids['pdp-price'].textContent = 'Price range';
  const script = element({ selectionMessage: 'Select color and size to see availability.' });
  script.textContent = JSON.stringify(rows);
  const buttons = [[0, 'Black'], [0, 'Green'], [1, 'S'], [1, 'M']].map(([axis, value]) => element({ axisIndex: String(axis), value }));
  buttons.forEach(button => button.closest = () => ({ querySelectorAll: () => buttons.filter(other => other.dataset.axisIndex === button.dataset.axisIndex) }));
  const document = {
    getElementById: id => ids[id] || null,
    querySelector: selector => selector === '[data-pdp-variants]' && variantMode ? script : null,
    querySelectorAll: selector => selector === '.js-pdp-variant-value' ? buttons : [],
    addEventListener: (name, handler) => handler(),
  };
  runInNewContext(source, { document, window: {}, JSON: { parse: () => rows }, setTimeout, clearTimeout });
  const choose = value => { const button = buttons.find(button => button.dataset.value === value); assert.equal(button.disabled, false); button.click(); };
  return { ids, buttons, choose, rows };
}

test('initial and partial selections hide stock and disable purchasing', () => {
  const { ids, choose } = mount();
  assert.equal(ids['pdp-availability'].textContent, 'Select color and size to see availability.');
  assert.equal(ids['pdp-add-cart'].disabled, true);
  assert.equal(ids['pdp-buy-now'].disabled, true);
  assert.equal(ids['pdp-qty-up'].disabled, true);
  choose('Black');
  assert.equal(ids['pdp-availability'].textContent, 'Select color and size to see availability.');
  assert.equal(ids['pdp-main-img'].src, '/black.jpg');
});

test('complete selection uses exact stock, price, ID and clamps quantity on size change', () => {
  const { ids, choose } = mount(); choose('Black'); choose('S');
  assert.equal(ids['pdp-availability'].textContent, '4 unit(s) available');
  assert.equal(ids['pdp-variant-id'].value, '1');
  assert.equal(ids['pdp-add-cart'].disabled, false);
  for (let i = 0; i < 10; i++) ids['pdp-qty-up'].click();
  assert.equal(ids['pdp-qty'].value, '4');
  assert.equal(ids['pdp-qty-up'].disabled, true);
  choose('M');
  assert.equal(ids['pdp-availability'].textContent, '2 unit(s) available');
  assert.equal(ids['pdp-price'].textContent, '\u20b1349.00');
  assert.equal(ids['pdp-qty'].value, '2');
  assert.equal(ids['pdp-main-img'].src, '/black.jpg');
  assert.equal(ids['pdp-variant-id'].value, '2');
  choose('Black');
  assert.equal(ids['pdp-buy-now'].disabled, true);
  assert.equal(ids['pdp-variant-id'].value, '');
});

test('sold-out colors are clearly disabled and cannot be purchased', () => {
  const { buttons } = mount();
  const green = buttons.find(button => button.dataset.value === 'Green');
  assert.equal(green.disabled, true);
  assert.equal(green.attributes['aria-disabled'], 'true');
});

test('non-variant quantity remains capped at normal stock', () => {
  const { ids } = mount(false);
  for (let i = 0; i < 25; i++) ids['pdp-qty-up'].click();
  assert.equal(ids['pdp-qty'].value, '17');
  assert.equal(ids['pdp-qty-up'].disabled, true);
  assert.equal(ids['pdp-add-cart'].disabled, false);
});

test('a selected variant becoming empty shows Out of Stock and disables both actions', () => {
  const { ids, rows, choose } = mount();
  choose('Black'); choose('S');
  rows[0].stock = 0;
  choose('S');
  assert.equal(ids['pdp-availability'].textContent, 'Out of Stock');
  assert.equal(ids['pdp-buy-now'].disabled, true);
  assert.equal(ids['pdp-add-cart'].disabled, true);
  assert.equal(ids['pdp-qty-up'].disabled, true);
  assert.equal(ids['pdp-qty'].value, '0');
});

test('manual quantity respects selected stock and normalizes invalid values', () => {
  const { ids, choose } = mount();
  assert.equal(ids['pdp-qty'].disabled, true);
  choose('Black'); choose('S');
  const quantity = ids['pdp-qty'];
  assert.equal(quantity.disabled, false);
  assert.equal(quantity.max, '4');
  quantity.value = '3'; quantity.input();
  assert.equal(quantity.value, '3');
  quantity.value = '99'; quantity.input();
  assert.equal(quantity.value, '4');
  quantity.value = '-5'; quantity.input();
  assert.equal(quantity.value, '1');
  quantity.value = '2.8'; quantity.input();
  assert.equal(quantity.value, '2');
  quantity.value = ''; quantity.input();
  assert.equal(quantity.value, '');
  quantity.blur();
  assert.equal(quantity.value, '1');
  quantity.value = '4'; quantity.input();
  choose('M');
  assert.equal(quantity.value, '2');
  assert.equal(quantity.max, '2');
});
