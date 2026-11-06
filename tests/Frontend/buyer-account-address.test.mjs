import test from 'node:test';
import assert from 'node:assert/strict';
import { accountAddress } from '../../resources/js/buyer/account-address.js';

globalThis.Option = class {
    constructor(text, value) { this.textContent = text; this.value = value; }
};

class Select {
    options = [];
    value = '';
    disabled = false;
    handlers = {};
    constructor(savedCode = '') { this.dataset = { savedCode }; }
    replaceChildren(...options) { this.options = options; this.value = options[0]?.value || ''; }
    add(option) { this.options.push(option); }
    addEventListener(event, callback) { this.handlers[event] = callback; }
    get selectedOptions() { return this.options.filter(option => option.value === this.value); }
}

function fixture(saved = ['', 'P', 'C', 'B']) {
    const selectors = ['#region', '#province', '#municipality', '#barangay'];
    const fields = saved.map(code => new Select(code));
    const names = ['#provinceNameField', '#municipalityNameField', '#barangayNameField'];
    const nodes = Object.fromEntries(selectors.map((selector, index) => [selector, fields[index]]));
    names.forEach(selector => { nodes[selector] = { value: 'old name' }; });
    const root = { dataset: { psgcBase: '/locations/' }, querySelector: selector => nodes[selector] };
    return { root, fields, nodes };
}

test('buyer account restores a province-only saved address through the Phase 2 cascade', async () => {
    const { root, fields, nodes } = fixture();
    const replies = {
        '/locations/provinces/P/': { regionCode: 'R' },
        '/locations/regions/': [{ code: 'R', name: 'Region' }],
        '/locations/regions/R/provinces/': [{ code: 'P', name: 'Province' }],
        '/locations/provinces/P/cities-municipalities/': [{ code: 'C', name: 'City' }],
        '/locations/cities-municipalities/C/barangays/': [{ code: 'B', name: 'Barangay' }],
    };
    await accountAddress(root, async path => {
        assert.ok(path in replies, path);
        return { ok: true, json: async () => replies[path] };
    });
    assert.deepEqual(fields.map(field => field.value), ['R', 'P', 'C', 'B']);
    assert.equal(nodes['#municipalityNameField'].value, 'City');
    fields[0].value = '';
    await fields[0].handlers.change();
    assert.deepEqual(fields.slice(1).map(field => field.value), ['', '', '']);
    assert.equal(nodes['#barangayNameField'].value, '');
});

test('buyer account still loads regions when the saved province lookup fails', async () => {
    const { root, fields, nodes } = fixture();
    await accountAddress(root, async path => {
        if (path.includes('/provinces/')) throw new Error('Offline');
        return { ok: true, json: async () => [{ code: 'R', name: 'Region' }] };
    });
    assert.equal(fields[0].disabled, false);
    assert.deepEqual(fields.slice(1).map(field => field.value), ['', '', '']);
    assert.equal(nodes['#municipalityNameField'].value, '');
});

test('buyer account respects the region submitted before validation failed', async () => {
    const { root, fields } = fixture(['R', '', '', '']);
    const calls = [];
    await accountAddress(root, async path => {
        calls.push(path);
        return { ok: true, json: async () => path.endsWith('/regions/') ? [{ code: 'R', name: 'Region' }] : [] };
    });
    assert.equal(fields[0].value, 'R');
    assert.deepEqual(calls, ['/locations/regions/', '/locations/regions/R/provinces/']);
});
