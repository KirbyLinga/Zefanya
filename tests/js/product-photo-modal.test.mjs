import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
const source = readFileSync(new URL('../../resources/js/buyer/product-show.js', import.meta.url), 'utf8');
function mount({ photos = ['/cover.jpg', '/back.jpg', '/detail.jpg'], variants = [] } = {}) {
    let document;
    class Element {
        constructor() { this.listeners = {}; this.style = {}; this.dataset = {}; this.attributes = {}; this.hidden = false; this.classList = { add() {}, remove() {}, toggle() {} }; }
        get src() { return this.attributes.src || ''; }
        set src(value) { this.attributes.src = value; }
        getAttribute(key) { return this.attributes[key]; }
        setAttribute(key, value) { this.attributes[key] = value; }
        addEventListener(type, handler) { (this.listeners[type] ||= []).push(handler); }
        dispatch(type, details = {}) { const event = { target: this, currentTarget: this, prevented: false, preventDefault() { this.prevented = true; }, ...details }; for (const fn of this.listeners[type] || []) fn(event); return event; }
        focus() { document.activeElement = this; }
        showModal() { this.open = true; }
        close() { this.open = false; this.dispatch('close'); }
        getBoundingClientRect() { return { left: 20, right: 380, top: 20, bottom: 600 }; }
    }
    const ids = Object.fromEntries(['pdp-main-img', 'pdp-image-open', 'pdp-maximize', 'pdp-image-modal', 'pdp-modal-img', 'pdp-image-counter', 'pdp-image-error', 'pdp-image-prev', 'pdp-image-next', 'pdp-image-close'].map(id => [id, new Element()]));
    ids['pdp-main-img'].src = photos[0] || ''; ids['pdp-main-img'].alt = 'Cotton shirt';
    const thumbs = photos.length > 1 ? photos.map(src => Object.assign(new Element(), { dataset: { src } })) : [];
    document = { body: { style: { overflow: 'auto' } }, activeElement: null, getElementById: id => ids[id] || null, querySelector: selector => selector === '[data-pdp-variants]' && variants.length ? { textContent: JSON.stringify(variants), dataset: {} } : null, querySelectorAll: selector => selector === '.js-pdp-thumb' ? thumbs : [], addEventListener: (_, fn) => fn() };
    runInNewContext(source, { document, window: { open() { throw Error('Photo viewer must not open a new tab'); } }, setTimeout, clearTimeout });
    return { ids, thumbs, document, click: id => ids[id].dispatch('click'), modal: ids['pdp-image-modal'], image: ids['pdp-modal-img'] };
}

test('main image, maximize and thumbnails open an on-page dialog at the clicked photo', () => {
    const ui = mount();
    for (const id of ['pdp-image-open', 'pdp-maximize']) {
        ui.click(id); assert.equal(ui.modal.open, true); assert.equal(ui.image.src, '/cover.jpg');
        assert.equal(ui.document.body.style.overflow, 'hidden'); ui.click('pdp-image-close');
    }
    ui.thumbs[2].dispatch('click'); assert.equal(ui.modal.open, true);
    assert.equal(ui.image.src, '/detail.jpg'); assert.equal(ui.ids['pdp-image-counter'].textContent, 'Photo 3 of 3');
});

test('next, previous and arrow keys wrap and update the page gallery consistently', () => {
    const ui = mount(); ui.click('pdp-image-open'); ui.click('pdp-image-prev');
    assert.equal(ui.image.src, '/detail.jpg');
    assert.equal(ui.modal.dispatch('keydown', { key: 'ArrowRight' }).prevented, true);
    assert.equal(ui.image.src, '/cover.jpg'); ui.click('pdp-image-next');
    assert.equal(ui.ids['pdp-main-img'].src, '/back.jpg'); assert.equal(ui.thumbs[1].attributes['aria-pressed'], 'true');
    assert.equal(ui.modal.dispatch('keydown', { key: 'Tab' }).prevented, false);
    ui.modal.dispatch('keydown', { key: 'ArrowLeft' }); assert.equal(ui.image.src, '/cover.jpg');
});

test('closing restores focus and the original scroll state; only backdrop clicks dismiss', () => {
    const ui = mount(); ui.click('pdp-maximize');
    ui.modal.dispatch('click', { clientX: 25, clientY: 25 }); assert.equal(ui.modal.open, true);
    ui.modal.dispatch('click', { clientX: 1, clientY: 1 }); assert.equal(ui.modal.open, false);
    assert.equal(ui.document.body.style.overflow, 'auto'); assert.equal(ui.document.activeElement, ui.ids['pdp-maximize']);
    ui.thumbs[1].dispatch('click'); ui.click('pdp-image-close'); assert.equal(ui.document.activeElement, ui.thumbs[1]);
});

test('single-photo products hide navigation and missing images do not open a viewer', () => {
    const single = mount({ photos: ['/only.jpg'] }); single.click('pdp-image-open');
    assert.equal(single.ids['pdp-image-prev'].hidden, true); assert.equal(single.ids['pdp-image-next'].hidden, true);
    assert.equal(single.ids['pdp-image-counter'].textContent, 'Photo 1 of 1');
    const empty = mount({ photos: [] }); empty.click('pdp-maximize'); assert.notEqual(empty.modal.open, true);
});

test('the selected color image opens correctly, with duplicate variant uploads shown once', () => {
    const ui = mount({ photos: ['/cover.jpg'], variants: [{ values: ['Black'], color_axis: 0, images: ['/black.jpg', '/black-back.jpg'], stock: 2, available: true }, { values: ['Black'], color_axis: 0, images: ['/black.jpg'], stock: 2, available: true }] });
    ui.ids['pdp-main-img'].src = '/black.jpg'; ui.click('pdp-image-open');
    assert.equal(ui.image.src, '/black.jpg'); assert.equal(ui.ids['pdp-image-counter'].textContent, 'Photo 2 of 3');
    ui.click('pdp-image-next'); assert.equal(ui.image.src, '/black-back.jpg');
});

test('failed images offer recovery and the error clears when another photo loads', () => {
    const ui = mount(); ui.click('pdp-image-open'); ui.image.dispatch('error');
    assert.equal(ui.ids['pdp-image-error'].hidden, false); ui.click('pdp-image-next');
    assert.equal(ui.ids['pdp-image-error'].hidden, true); ui.image.dispatch('error'); ui.image.dispatch('load');
    assert.equal(ui.ids['pdp-image-error'].hidden, true);
});


test('Tab and Shift+Tab wrap within the visible dialog controls', () => {
    const ui = mount(); ui.click('pdp-image-open');
    ui.ids['pdp-image-next'].focus();
    assert.equal(ui.modal.dispatch('keydown', { key: 'Tab' }).prevented, true);
    assert.equal(ui.document.activeElement, ui.ids['pdp-image-close']);
    assert.equal(ui.modal.dispatch('keydown', { key: 'Tab', shiftKey: true }).prevented, true);
    assert.equal(ui.document.activeElement, ui.ids['pdp-image-next']);
    const single = mount({ photos: ['/only.jpg'] }); single.click('pdp-image-open'); single.ids['pdp-image-close'].focus();
    assert.equal(single.modal.dispatch('keydown', { key: 'Tab' }).prevented, true);
    assert.equal(single.document.activeElement, single.ids['pdp-image-close']);
});
