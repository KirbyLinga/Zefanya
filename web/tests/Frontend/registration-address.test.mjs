import test from 'node:test';
import assert from 'node:assert/strict';
import { registrationAddress } from '../../resources/js/registration-address.js';

globalThis.Option = class {
    constructor(text, value) { this.textContent = text; this.value = value; }
};

class Select {
    options = [];
    value = '';
    disabled = false;
    handlers = {};
    replaceChildren(...options) { this.options = options; this.value = options[0]?.value || ''; }
    add(option) { this.options.push(option); }
    addEventListener(event, callback) { this.handlers[event] = callback; }
    get selectedOptions() { return this.options.filter(option => option.value === this.value); }
    change(value) { this.value = value; return this.handlers.change(); }
}

async function fixture() {
    const fields = Array.from({ length: 4 }, () => new Select());
    const names = Array.from({ length: 4 }, () => ({ value: '' }));
    const form = { handlers: {}, addEventListener(event, callback) { this.handlers[event] = callback; } };
    fields[0].form = form;
    const pending = new Map();
    const start = registrationAddress({
        region: fields[0], province: fields[1], city: fields[2], barangay: fields[3], names, base: '',
        fetcher: path => new Promise(resolve => pending.set(path, data => resolve({ ok: true, json: async () => data }))),
    });
    const loading = start();
    pending.get('/regions/')([{ code: 'A', name: 'Region A' }, { code: 'B', name: 'Region B' }]);
    await loading;
    return { fields, names, pending, form };
}

test('region change immediately clears child codes and names and ignores stale city replies', async () => {
    const { fields: [region, province, city, barangay], names, pending } = await fixture();
    const first = region.change('A');
    pending.get('/regions/A/provinces/')([{ code: 'P', name: 'Province P' }]);
    await first;
    const oldCities = province.change('P');
    barangay.value = 'old-barangay';
    names[3].value = 'Old barangay';
    const second = region.change('B');
    assert.deepEqual([province.value, city.value, barangay.value], ['', '', '']);
    assert.deepEqual(names.slice(1).map(field => field.value), ['', '', '']);
    assert.equal(city.disabled, true);
    pending.get('/provinces/P/cities-municipalities/')([{ code: 'OLD', name: 'Old city' }]);
    await oldCities;
    assert.equal(city.options.some(option => option.value === 'OLD'), false);
    assert.equal(city.disabled, true);
    pending.get('/regions/B/provinces/')([{ code: 'Q', name: 'Province Q' }]);
    await second;
    assert.deepEqual(province.options.map(option => option.value), ['', 'Q']);
});

test('city change clears barangay and ignores an older barangay response', async () => {
    const { fields: [region, province, city, barangay], names, pending } = await fixture();
    let loading = region.change('A');
    pending.get('/regions/A/provinces/')([{ code: 'P', name: 'Province P' }]);
    await loading;
    loading = province.change('P');
    pending.get('/provinces/P/cities-municipalities/')([{ code: 'C', name: 'City C' }, { code: 'D', name: 'City D' }]);
    await loading;
    const old = city.change('C');
    names[3].value = 'Old barangay';
    const current = city.change('D');
    assert.equal(barangay.value, '');
    assert.equal(names[3].value, '');
    pending.get('/cities-municipalities/C/barangays/')([{ code: 'OLD', name: 'Old barangay' }]);
    await old;
    assert.equal(barangay.disabled, true);
    pending.get('/cities-municipalities/D/barangays/')([{ code: 'NEW', name: 'New barangay' }]);
    await current;
    assert.deepEqual(barangay.options.map(option => option.value), ['', 'NEW']);
});

test('a region without provinces uses its region code to load cities', async () => {
    const { fields: [region, province], pending } = await fixture();
    const loading = region.change('A');
    pending.get('/regions/A/provinces/')([]);
    await loading;
    assert.deepEqual(province.options.map(option => option.value), ['', 'A']);
    const cities = province.change('A');
    assert.equal(pending.has('/regions/A/cities-municipalities/'), true);
    pending.get('/regions/A/cities-municipalities/')([]);
    await cities;
});

test('NCR supplies Metro Manila without requesting a nonexistent province list', async () => {
    const { fields: [region, province], pending } = await fixture();
    region.add(new Option('NCR', '130000000'));
    await region.change('130000000');
    assert.equal(pending.has('/regions/130000000/provinces/'), false);
    assert.deepEqual(province.options.map(option => option.value), ['', '130000000']);
    const cities = province.change('130000000');
    pending.get('/regions/130000000/cities-municipalities/')([]);
    await cities;
});

test('clearing a province clears its name and both children immediately', async () => {
    const { fields: [region, province, city, barangay], names, pending } = await fixture();
    const loading = region.change('A');
    pending.get('/regions/A/provinces/')([{ code: 'P', name: 'Province P' }]);
    await loading;
    const cities = province.change('P');
    city.value = 'OLD-CITY';
    barangay.value = 'OLD-BARANGAY';
    names[2].value = 'Old city';
    names[3].value = 'Old barangay';
    await province.change('');
    assert.deepEqual([province.value, city.value, barangay.value], ['', '', '']);
    assert.deepEqual(names.slice(1).map(field => field.value), ['', '', '']);
    assert.equal(city.disabled, true);
    assert.equal(barangay.disabled, true);
    pending.get('/provinces/P/cities-municipalities/')([{ code: 'STALE', name: 'Stale city' }]);
    await cities;
    assert.deepEqual(city.options.map(option => option.value), ['']);
});

test('form reset invalidates pending location replies and clears hidden names', async () => {
    const { fields: [region, province, city, barangay], names, pending, form } = await fixture();
    const loading = region.change('A');
    form.handlers.reset();
    // Simulate native reset restoring the initial hidden values.
    names[1].value = 'Original province';
    await Promise.resolve();
    pending.get('/regions/A/provinces/')([{ code: 'STALE', name: 'Stale province' }]);
    await loading;
    assert.deepEqual([province.value, city.value, barangay.value], ['', '', '']);
    assert.deepEqual(names.slice(1).map(field => field.value), ['', '', '']);
    assert.equal(province.disabled, true);
});

test('saved application address is restored through each validated parent list', async () => {
    const fields = Array.from({ length: 4 }, () => new Select());
    const names = Array.from({ length: 4 }, () => ({ value: '' }));
    const responses = {
        '/regions/': [{ code: 'R', name: 'Region' }],
        '/regions/R/provinces/': [{ code: 'P', name: 'Province' }],
        '/provinces/P/cities-municipalities/': [{ code: 'C', name: 'City' }],
        '/cities-municipalities/C/barangays/': [{ code: 'B', name: 'Barangay' }],
    };
    const start = registrationAddress({
        region: fields[0], province: fields[1], city: fields[2], barangay: fields[3], names, base: '',
        initial: ['R', 'P', 'C', 'B'],
        fetcher: async path => ({ ok: true, json: async () => responses[path] }),
    });
    await start();
    assert.deepEqual(fields.map(field => field.value), ['R', 'P', 'C', 'B']);
    assert.deepEqual(names.map(field => field.value), ['Region', 'Province', 'City', 'Barangay']);
});
