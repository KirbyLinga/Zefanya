import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Exercise the real event handlers without booting the rest of the modal or a browser.
const source = readFileSync(new URL('../../resources/js/seller/product-create-modal.js', import.meta.url), 'utf8');
const functions = source.slice(source.indexOf('    function addValue('), source.indexOf('    function open()'));
function listener(type) {
    const start = source.indexOf(`    form.addEventListener('${type}', event => {`);
    const end = source.indexOf('\n    });', start) + '\n    });'.length;
    return source.slice(start, end);
}

function fixture(value = 'Blue', existing = ['Black'], extraAxes = []) {
    const listeners = {};
    let posts = 0;
    let error = '';
    let focused = '';
    const row = { dataset: { index: '0' }, querySelector: () => entry };
    const entry = { value, matches: selector => selector === '[data-pf-value-entry]', closest: () => row, focus: () => { focused = 'entry'; } };
    const state = { axes: [{ name: 'Color', values: [...existing] }, ...extraAxes] };
    const context = vm.createContext({
        state,
        form: { dataset: {}, addEventListener: (type, callback) => { listeners[type] = callback; } },
        qa: () => [entry],
        q: selector => selector === '#status' ? { value: 'active' } : { focus: () => { focused = selector; }, checkValidity: () => true },
        asValues: text => text.split(',').map(part => part.trim()).filter(Boolean),
        error: message => { error = message; },
        renderOptions: () => {}, generate: () => {}, rollup: () => {}, saveRows: () => {},
        enabled: () => true,
        LAST_STEP: 4, validate: () => true, persistForm: () => { posts++; }, showStep: () => {},
    });
    vm.runInContext(functions + listener('submit') + listener('focusout') + listener('keydown') + listener('click'), context);
    return { listeners, state, entry, get posts() { return posts; }, get error() { return error; }, get focused() { return focused; } };
}

test('publish and draft both stop on an invalid pending option rather than silently dropping it', () => {
    for (const draft of [false, true]) {
        for (const [value, existing] of [[ 'a'.repeat(51), ['Black'] ], ['New', Array.from({ length: 20 }, (_, n) => `Value ${n}`)]]) {
            const form = fixture(value, existing);
            form.listeners.submit({ preventDefault() {}, submitter: { matches: () => draft } });
            assert.equal(form.posts, 0);
            assert.ok(form.error);
            assert.equal(form.entry.value, value);
            assert.equal(form.focused, 'entry');
            assert.deepEqual(Array.from(form.state.axes[0].values), existing);
        }
    }
});

test('publish commits a pending valid option before submitting without needing an Add click', () => {
    const form = fixture('Blue, Red');
    form.listeners.submit({ preventDefault() {}, submitter: { matches: () => false } });
    assert.equal(form.posts, 1);
    assert.deepEqual(Array.from(form.state.axes[0].values), ['Black', 'Blue', 'Red']);
});

test('Tab or click to another option input keeps focus after auto-committing the pending value', () => {
    const form = fixture();
    const nextField = {
        closest: selector => selector === 'button' ? null : { dataset: { index: '1' } },
        matches: selector => selector === '[data-pf-option-name]',
    };
    form.listeners.focusout({ target: form.entry, relatedTarget: nextField });
    assert.deepEqual(Array.from(form.state.axes[0].values), ['Black', 'Blue']);
    assert.equal(form.focused, '[data-pf-option-row][data-index="1"] [data-pf-option-name]');
});

test('leaving a pending value for an action button does not replace the button before its click', () => {
    const form = fixture();
    form.listeners.focusout({ target: form.entry, relatedTarget: { closest: () => ({}) } });
    assert.deepEqual(Array.from(form.state.axes[0].values), ['Black']);
});

test('Enter and comma commit an option without triggering product publication', () => {
    for (const key of ['Enter', ',']) {
        const form = fixture();
        let prevented = false;
        form.listeners.keydown({ key, target: form.entry, preventDefault: () => { prevented = true; } });
        assert.equal(prevented, true);
        assert.equal(form.posts, 0);
        assert.deepEqual(Array.from(form.state.axes[0].values), ['Black', 'Blue']);
    }
});

test('adding a variation group commits the typed option before rebuilding controls', () => {
    const form = fixture();
    const button = { matches: selector => selector === '[data-pf-add-option]', closest: selector => selector === 'button' ? button : null };
    form.listeners.click({ target: button });
    assert.equal(form.state.axes.length, 2);
    assert.deepEqual(Array.from(form.state.axes[0].values), ['Black', 'Blue']);
});

test('adding a variation group stops when its pending option is invalid', () => {
    const form = fixture('a'.repeat(51));
    const button = { matches: selector => selector === '[data-pf-add-option]', closest: selector => selector === 'button' ? button : null };
    form.listeners.click({ target: button });
    assert.equal(form.state.axes.length, 1);
    assert.ok(form.error);
    assert.equal(form.entry.value, 'a'.repeat(51));
});

test('removing another variation group preserves pending options in the retained group', () => {
    const form = fixture('Blue', ['Black'], [{ name: 'Size', values: ['Small'] }]);
    const removedRow = { dataset: { index: '1' } };
    const button = { matches: selector => selector === '[data-pf-remove-option]', closest: selector => selector === 'button' ? button : removedRow };
    form.listeners.click({ target: button });
    assert.equal(form.state.axes.length, 1);
    assert.equal(form.state.axes[0].name, 'Color');
    assert.deepEqual(Array.from(form.state.axes[0].values), ['Black', 'Blue']);
});

test('removing a committed option preserves the pending new option before rebuilding controls', () => {
    const form = fixture('Blue', ['Black', 'Red']);
    const row = { dataset: { index: '0' } };
    const button = {
        dataset: { valueIndex: '0' },
        matches: selector => selector === '[data-pf-remove-value]',
        closest: selector => selector === 'button' ? button : row,
    };
    form.listeners.click({ target: button });
    assert.deepEqual(Array.from(form.state.axes[0].values), ['Red', 'Blue']);
});
