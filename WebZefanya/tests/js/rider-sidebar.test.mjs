import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const template = readFileSync(new URL('../../resources/views/Components/rider/sidebar.blade.php', import.meta.url), 'utf8');
const script = template.match(/<script>([\s\S]*?)<\/script>/)[1];

function mount({ mobile = false, saved = null, storageBlocked = false } = {}) {
    const classes = new Set();
    const attributes = {};
    const stored = {};
    let click;
    const sidebar = {
        classList: {
            toggle(name, enabled) { enabled ? classes.add(name) : classes.delete(name); },
            contains(name) { return classes.has(name); },
        },
    };
    const toggle = {
        setAttribute(name, value) { attributes[name] = value; },
        addEventListener(event, handler) { if (event === 'click') click = handler; },
    };
    runInNewContext(script, {
        document: { getElementById(id) { return id === 'riderSidebar' ? sidebar : toggle; } },
        window: { matchMedia() { return { matches: mobile }; } },
        localStorage: {
            getItem() { if (storageBlocked) throw new Error('Storage blocked'); return saved; },
            setItem(key, value) { if (storageBlocked) throw new Error('Storage blocked'); stored[key] = value; },
        },
    });
    return { sidebar, attributes, stored, click: () => click() };
}

for (const saved of [null, '0', '1']) {
    test(`mobile starts with the collapsed rail when preference is ${saved}`, () => {
        const panel = mount({ mobile: true, saved });
        assert.equal(panel.sidebar.classList.contains('is-collapsed'), true);
        assert.equal(panel.attributes['aria-expanded'], 'false');
        panel.click();
        assert.equal(panel.sidebar.classList.contains('is-collapsed'), false);
        assert.equal(panel.attributes['aria-expanded'], 'true');
        assert.equal(panel.attributes['aria-label'], 'Collapse sidebar');
        assert.equal(panel.stored['rider.sidebar.collapsed'], '0');
        panel.click();
        assert.equal(panel.sidebar.classList.contains('is-collapsed'), true);
        assert.equal(panel.stored['rider.sidebar.collapsed'], '1');
    });
}

for (const saved of [null, '0', '1']) {
    test(`desktop retains sidebar preference ${saved}`, () => {
        const panel = mount({ saved });
        assert.equal(panel.sidebar.classList.contains('is-collapsed'), saved === '1');
        assert.equal(panel.attributes['aria-expanded'], saved === '1' ? 'false' : 'true');
    });
}

test('mobile sidebar remains usable when storage is blocked', () => {
    const panel = mount({ mobile: true, storageBlocked: true });
    assert.equal(panel.attributes['aria-expanded'], 'false');
    panel.click();
    assert.equal(panel.attributes['aria-expanded'], 'true');
});
