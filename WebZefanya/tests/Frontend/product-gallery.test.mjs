import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/seller/product-create-modal.js', import.meta.url), 'utf8');
const functions = source.slice(source.indexOf('    function preview('), source.indexOf('    function initialize('));
const photo = (name, overrides = {}) => ({ name, type: 'image/png', size: 100, lastModified: 1, ...overrides });
function mount({ edit = false, saved = 0 } = {}) {
    class Element {
        constructor(tag, text) { this.tag = tag; this.textContent = text; this.children = []; this.dataset = {}; this.attributes = {}; this.classList = { add() {} }; }
        append(...nodes) { this.children.push(...nodes); }
        replaceChildren(...nodes) { this.children = nodes; }
        setAttribute(key, value) { this.attributes[key] = value; }
        querySelectorAll() { return this.children.flatMap(child => [...('data-object-url' in child.attributes ? [child] : []), ...child.querySelectorAll()]); }
    }
    const input = { files: [] }; const cover = { value: '0', disabled: edit }; const gallery = new Element('div'); const count = {};
    let error = ''; let url = 0; const revoked = [];
    const state = { galleryFiles: [], edit, urls: new Set() };
    const context = vm.createContext({
        state,
        q: selector => ({ '[data-pf-gallery-files]': input, '[data-pf-cover-index]': cover, '[data-pf-gallery]': gallery, '[data-pf-gallery-count]': count })[selector],
        qa: () => Array(saved).fill({}),
        node: (tag, text) => new Element(tag, text), action: text => new Element('button', text),
        error: message => { error = message; },
        URL: { createObjectURL: () => `blob:${++url}`, revokeObjectURL: value => revoked.push(value) },
        DataTransfer: class { constructor() { this.files = []; this.items = { add: file => this.files.push(file) }; } },
    });
    vm.runInContext(functions, context);
    return { input, cover, gallery, count, state, revoked, get error() { return error; }, choose: files => { input.files = files; context.addGalleryFiles(files); }, remove: index => context.removeGalleryFile(index), setCover: index => context.setGalleryFiles(state.galleryFiles, index, false) };
}
const names = ui => Array.from(ui.input.files, file => file.name);

test('cover and three angle photos can be added in separate selections and submitted together', () => {
    const ui = mount();
    ui.choose([photo('cover.png')]); ui.choose([photo('front.png')]); ui.choose([photo('back.png'), photo('detail.png')]);
    assert.deepEqual(names(ui), ['cover.png', 'front.png', 'back.png', 'detail.png']);
    assert.equal(ui.cover.value, '0'); assert.equal(ui.cover.disabled, false);
    assert.equal(ui.gallery.children.length, 4);
    assert.equal(ui.gallery.children[0].children[1].textContent, 'Cover image');
    assert.match(ui.count.textContent, /4 new photos/);
    assert.ok(ui.gallery.children.every(figure => figure.children[2].attributes['aria-label'].startsWith('Remove ')));
});

test('adding more angles and removing earlier photos keeps the chosen cover on the same image', () => {
    const ui = mount(); ui.choose([photo('front.png'), photo('cover.png')]); ui.setCover(1);
    ui.choose([photo('back.png')]); assert.equal(ui.cover.value, '1');
    ui.remove(0); assert.deepEqual(names(ui), ['cover.png', 'back.png']); assert.equal(ui.cover.value, '0');
    assert.equal(ui.gallery.children[0].children[1].textContent, 'Cover image');
    assert.ok(ui.revoked.length > 0); assert.equal(ui.state.urls.size, 2);
});

test('removing the selected cover picks the first remaining photo and removing all omits cover_index', () => {
    const ui = mount(); ui.choose([photo('front.png'), photo('cover.png')]); ui.setCover(1); ui.remove(1);
    assert.deepEqual(names(ui), ['front.png']); assert.equal(ui.cover.disabled, false);
    ui.remove(0); assert.deepEqual(names(ui), []); assert.equal(ui.cover.disabled, true);
    ui.choose([photo('replacement.png')]); assert.equal(ui.cover.disabled, false);
});

test('invalid, oversized and excessive uploads retain previous selections and cover', () => {
    for (const addition of [[photo('bad.svg', { type: 'image/svg+xml' })], [photo('large.png', { size: 5 * 1024 * 1024 + 1 })], Array.from({ length: 9 }, (_, i) => photo(`extra-${i}.png`))]) {
        const ui = mount(); ui.choose([photo('front.png'), photo('cover.png')]); ui.setCover(1); ui.choose(addition);
        assert.deepEqual(names(ui), ['front.png', 'cover.png']); assert.equal(ui.cover.value, '1');
        assert.match(ui.error, /9 JPG\/PNG images in total/);
    }
});

test('editing preserves the saved cover, counts saved photos against the cap, and allows an explicit replacement', () => {
    const ui = mount({ edit: true, saved: 8 }); ui.choose([photo('angle.png')]);
    assert.equal(ui.cover.disabled, true); assert.match(ui.count.textContent, /saved cover will be kept/);
    ui.choose([photo('too-many.png')]); assert.deepEqual(names(ui), ['angle.png']); assert.ok(ui.error);
    ui.setCover(0); assert.equal(ui.cover.disabled, false); assert.match(ui.count.textContent, /One cover image/);
});

test('cancelling or selecting the same photo again preserves the staged upload list', () => {
    const ui = mount(); ui.choose([photo('cover.png')]); ui.choose([]); ui.choose([photo('cover.png')]);
    assert.deepEqual(names(ui), ['cover.png']); assert.equal(ui.cover.value, '0'); assert.equal(ui.error, '');
});
