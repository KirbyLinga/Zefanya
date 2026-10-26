const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor() {
        this.children = [];
        this.dataset = {};
        this.listeners = {};
        this.value = '';
        this.classList = { add() {}, toggle() {} };
    }
    addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
    emit(name, event = {}) { (this.listeners[name] || []).forEach(callback => callback(event)); }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    add(child) { this.children.push(child); }
    focus() { this.focused = true; }
}

async function page({ single = false, confirm = true } = {}) {
    const root = new Element();
    const selectors = ['coverage-station', 'coverage-municipality', 'coverage-municipality-name', 'coverage-barangays', 'barangay-search', 'barangay-select-all', 'barangay-status'];
    const fields = Object.fromEntries(selectors.map(name => [name, new Element()]));
    fields['coverage-station'].value = '1';
    fields['coverage-station'].selectedOptions = [{ dataset: { provinceCode: 'province', regionCode: 'region' } }];
    fields['coverage-municipality'].dataset.selectedCode = 'city';
    fields['coverage-municipality'].selectedOptions = [{ textContent: 'Los Banos' }];
    fields['coverage-barangays'].dataset = { selected: '[]', single: single ? '1' : '0' };
    root.dataset = { claims: JSON.stringify([{ code: 'b1', station: 'Station X' }]), apiBase: '' };
    root.querySelector = selector => fields[selector.slice(6, -1)];
    const confirmation = new Element();
    confirmation.dataset.confirm = 'Unlink affects 1 rider and 2 pending pickups';
    const document = new Element();
    document.createElement = () => new Element();
    document.querySelectorAll = selector => selector === '[data-coverage-form]' ? [root] : selector === 'form[data-confirm]' ? [confirmation] : [];
    let confirmationText;
    vm.runInNewContext(fs.readFileSync('resources/js/logistics/coverage-lookup.js', 'utf8'), {
        document,
        window: { confirm: text => { confirmationText = text; return confirm; } },
        Option: function (text, value) { this.textContent = text; this.value = value; },
        fetch: async url => ({ ok: true, json: async () => url.includes('/municipalities')
            ? [{ name: 'Los Banos', code: 'city' }]
            : [{ name: 'Bayog', code: 'b1' }, { name: 'Maahas', code: 'b2' }, { name: 'Dangka', code: 'b3' }] }),
    });
    document.emit('DOMContentLoaded');
    await new Promise(resolve => setImmediate(resolve));
    return { root, fields, confirmation, confirmationText: () => confirmationText };
}

function inputs(list) {
    return list.children.flatMap(child => child.children.length ? child.children.filter(input => input.name) : child.name ? [child] : []);
}

test('claimed barangays show the owner and Select all excludes them', async () => {
    const { fields } = await page();
    const list = fields['coverage-barangays'];
    const bayog = list.children.find(label => label.children[0]?.value === 'b1');
    assert.equal(bayog.children[0].disabled, true);
    assert.equal(bayog.children[1].textContent, 'Bayog: claimed by Station X');
    fields['barangay-select-all'].emit('click');
    assert.deepEqual(inputs(list).filter(input => input.checked).map(input => input.value).sort(), ['b2', 'b3']);
});

test('search retains selected barangays in the submitted values', async () => {
    const { fields } = await page();
    fields['barangay-select-all'].emit('click');
    fields['barangay-search'].value = 'Maahas';
    fields['barangay-search'].emit('input');
    assert.deepEqual(inputs(fields['coverage-barangays']).filter(input => input.checked || input.type === 'hidden').map(input => input.value).sort(), ['b2', 'b3']);
});

test('legacy conversion selects one barangay and an empty selection blocks submission', async () => {
    const { root, fields } = await page({ single: true });
    let prevented = false;
    root.emit('submit', { preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    let input = inputs(fields['coverage-barangays']).find(input => input.value === 'b2');
    input.checked = true;
    input.emit('change');
    input = inputs(fields['coverage-barangays']).find(input => input.value === 'b3');
    input.checked = true;
    input.emit('change');
    const selected = inputs(fields['coverage-barangays']).filter(input => input.checked);
    assert.equal(selected.length, 1);
    assert.equal(selected[0].value, 'b3');
    assert.equal(selected[0].name, 'barangay_code');
});

test('canceling the impact confirmation stops the action', async () => {
    const result = await page({ confirm: false });
    let prevented = false;
    result.confirmation.emit('submit', { preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(result.confirmationText(), 'Unlink affects 1 rider and 2 pending pickups');
});
