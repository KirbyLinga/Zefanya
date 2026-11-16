import test from 'node:test';
import assert from 'node:assert/strict';
import { clearProductValidationErrors, showProductValidationErrors } from '../../resources/js/seller/product-validation-errors.js';

class Element {
    attributes = new Map();
    classes = new Set();
    classList = { add: (...values) => values.forEach(value => this.classes.add(value)), remove: (...values) => values.forEach(value => this.classes.delete(value)) };
    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); }
    remove() { this.removed = true; }
    closest(selector) { return selector === '[data-pf-step]' ? this.panel : this.row; }
    insertAdjacentElement(position, message) { assert.equal(position, 'afterend'); this.root.messages.push(message); }
}

globalThis.document = { createElement: () => new Element() };

function fixture() {
    const root = {
        fields: [], rows: [], messages: [],
        querySelectorAll(selector) {
            if (selector === '[name]') return this.fields;
            if (selector === '[data-pf-field-error]') return this.messages.filter(message => !message.removed);
            if (selector === '[data-pf-invalid]') return [...this.fields, ...this.rows].filter(element => element.attributes.has('data-pf-invalid'));
            throw new Error(`Unexpected selector ${selector}`);
        },
        querySelector() { return null; },
    };
    function field(name, step, rowIndex, type = 'number') {
        const row = root.rows[rowIndex] ||= new Element();
        const input = Object.assign(new Element(), { name, type, root, row, panel: { dataset: { pfStep: String(step) } } });
        root.fields.push(input);
        row.querySelector = () => root.fields.find(item => item.row === row && item.type !== 'hidden');
        return input;
    }
    return { root, field };
}

test('server SKU error highlights only its exact field and variant row', () => {
    const { root, field } = fixture();
    const good = field('variants[0][sku]', 4, 0, 'text');
    const bad = field('variants[1][sku]', 4, 1, 'text');
    const first = showProductValidationErrors(root, { 'variants.1.sku': ['This SKU is already used.'] });
    assert.equal(first.step, 4);
    assert.equal(first.field, bad);
    assert.equal(bad.attributes.get('aria-invalid'), 'true');
    assert.equal(root.rows[1].attributes.has('data-pf-invalid'), true);
    assert.equal(good.attributes.has('aria-invalid'), false);
    assert.equal(root.rows[0].attributes.has('data-pf-invalid'), false);
    assert.equal(root.messages[0].textContent, 'This SKU is already used.');
});

test('normalized weight maps to editable input and earliest invalid panel is selected', () => {
    const { root, field } = fixture();
    const weight = field('variants[0][weight]', 4, 0);
    const name = field('options[0][name]', 2, 1, 'text');
    const first = showProductValidationErrors(root, { 'variants.0.weight_grams': ['Enter a positive weight.'], 'options.0.name': ['Enter an option name.'] });
    assert.equal(weight.attributes.get('aria-invalid'), 'true');
    assert.equal(name.attributes.get('aria-invalid'), 'true');
    assert.equal(first.step, 2);
    assert.equal(first.field, name);
});

test('hidden combination error focuses an editable row field and treats messages as text', () => {
    const { root, field } = fixture();
    field('variants[0][values]', 4, 0, 'hidden');
    const price = field('variants[0][price]', 4, 0);
    const first = showProductValidationErrors(root, { 'variants.0.values': ['<img src=x onerror=alert(1)>'] });
    assert.equal(first.field, price);
    assert.equal(root.messages[0].textContent, '<img src=x onerror=alert(1)>');
    assert.equal(root.messages[0].innerHTML, undefined);
});

test('retry clears stale field and row highlights plus inline messages', () => {
    const { root, field } = fixture();
    const price = field('variants[0][price]', 4, 0);
    showProductValidationErrors(root, { 'variants.0.price': ['Invalid price.'] });
    clearProductValidationErrors(root);
    assert.equal(price.attributes.has('aria-invalid'), false);
    assert.equal(price.classes.size, 0);
    assert.equal(root.rows[0].classes.size, 0);
    assert.equal(root.querySelectorAll('[data-pf-field-error]').length, 0);
});
