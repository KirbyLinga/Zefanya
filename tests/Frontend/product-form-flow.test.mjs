import test from 'node:test';
import assert from 'node:assert/strict';
import { refreshProductSections, updateStoreCategoryChoices, revealProductField } from '../../resources/js/seller/product-form-flow.js';

function sections() {
    const panels = [1, 2, 3, 4].map(step => ({ dataset: { pfStep: String(step) }, hidden: true }));
    const colorPhotos = { hidden: true };
    return { panels, colorPhotos, root: { querySelectorAll: () => panels, querySelector: () => colorPhotos } };
}

test('only the current step shows', () => {
    const { panels, root } = sections();
    for (const step of [1, 2, 3, 4]) {
        refreshProductSections(root, { step, variations: false, colorFirst: true, hasColor: false });
        assert.deepEqual(panels.filter(panel => !panel.hidden).map(panel => Number(panel.dataset.pfStep)), [step]);
    }
});

test('color-first versions keep their photos on each color, so the separate section stays hidden', () => {
    const { colorPhotos, root } = sections();
    refreshProductSections(root, { step: 2, variations: true, colorFirst: true, hasColor: true });
    assert.equal(colorPhotos.hidden, true);
});

test('existing custom color variations retain their dedicated upload section only while needed', () => {
    const { colorPhotos, root } = sections();
    refreshProductSections(root, { step: 2, variations: true, colorFirst: false, hasColor: true });
    assert.equal(colorPhotos.hidden, false);
    refreshProductSections(root, { step: 2, variations: true, colorFirst: false, hasColor: false });
    assert.equal(colorPhotos.hidden, true);
});

test('category choices allow unchecking at the three-category limit and enable choices again afterwards', () => {
    const boxes = [true, true, true, false].map(checked => ({ checked, disabled: false }));
    const count = {};
    const root = { querySelectorAll: () => boxes, querySelector: () => count };
    updateStoreCategoryChoices(root);
    assert.deepEqual(boxes.map(box => box.disabled), [false, false, false, true]);
    assert.equal(count.textContent, '3 of 3 selected');
    boxes[0].checked = false;
    updateStoreCategoryChoices(root);
    assert.deepEqual(boxes.map(box => box.disabled), [false, false, false, false]);
    assert.equal(count.textContent, '2 of 3 selected');
});

test('validation reveals collapsed optional ancestors before scrolling to the invalid field', () => {
    const outer = { open: false, parentElement: { closest: () => null } };
    const inner = { open: false, parentElement: { closest: () => outer } };
    let scrolled = false;
    revealProductField({ closest: () => inner, scrollIntoView: () => { assert.equal(inner.open, true); assert.equal(outer.open, true); scrolled = true; } });
    assert.equal(scrolled, true);
});
