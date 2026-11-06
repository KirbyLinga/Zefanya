import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
const source = readFileSync(new URL('../../resources/js/buyer/checkout-carriers.js', import.meta.url), 'utf8');
function mount({ fee = 5000, available = true, voucherType = 'fixed', voucherValue = 1000 } = {}) {
    const fields = new Map(); const events = {}; const windowEvents = {};
    const field = name => { const node = { textContent: '', hidden: false }; fields.set(name, node); return node; };
    for (const key of ['shipping-total', 'shipping-discount-row', 'shipping-discount', 'payable-total', 'savings-row', 'savings', 'shipping-block']) field(key);
    const label = {}; const button = { disabled: false, querySelector: () => label };
    const attributes = { 'data-merchandise-minor': '10000', 'data-merchandise-savings-minor': '2000', 'data-shipping-voucher-type': voucherType, 'data-shipping-voucher-value': String(voucherValue) };
    const group = { getAttribute: () => available ? '1' : '0', querySelector: () => ({ getAttribute: () => String(fee) }) };
    const root = { getAttribute: key => attributes[key], setAttribute: (key, value) => attributes[key] = value, querySelectorAll: () => [group], addEventListener: (key, fn) => events[key] = fn };
    const document = { readyState: 'complete', querySelector: selector => selector === '[data-checkout-carriers]' ? root : fields.get(selector.slice(15, -1)), querySelectorAll: () => [button] };
    runInNewContext(source, { document, window: { addEventListener: (key, fn) => windowEvents[key] = fn } });
    return { fields, button, label, attributes, pageshow: () => windowEvents.pageshow(), submit: () => { const event = { prevented: false, preventDefault() { this.prevented = true; } }; events.submit(event); return event; } };
}
test('checkout shows gross delivery fee, voucher savings and net payable separately', () => {
    const ui = mount();
    assert.equal(ui.fields.get('shipping-total').textContent, '\u20b150.00');
    assert.equal(ui.fields.get('shipping-discount').textContent, '-\u20b110.00');
    assert.equal(ui.fields.get('payable-total').textContent, '\u20b1140.00');
    assert.equal(ui.fields.get('savings').textContent, '\u20b130.00');
    assert.equal(ui.button.disabled, false);
});
test('free delivery does not apply a shipping voucher twice', () => {
    const ui = mount({ fee: 0 });
    assert.equal(ui.fields.get('shipping-total').textContent, 'Free');
    assert.equal(ui.fields.get('shipping-discount-row').hidden, true);
    assert.equal(ui.fields.get('payable-total').textContent, '\u20b1100.00');
    assert.equal(ui.fields.get('savings').textContent, '\u20b120.00');
});
test('unavailable carrier blocks submission and explains recovery', () => {
    const ui = mount({ available: false });
    assert.equal(ui.fields.get('shipping-total').textContent, 'Select a carrier');
    assert.equal(ui.fields.get('shipping-block').hidden, false);
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.submit().prevented, true);
});
test('checkout prevents duplicate submission and restores controls on browser return', () => {
    const ui = mount();
    assert.equal(ui.submit().prevented, false);
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.attributes['aria-busy'], 'true');
    assert.equal(ui.submit().prevented, true);
    ui.pageshow();
    assert.equal(ui.button.disabled, false);
    assert.equal(ui.attributes['aria-busy'], 'false');
    assert.equal(ui.submit().prevented, false);
});
