import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const component = readFileSync(new URL('../../resources/views/Components/confirm-dialog.blade.php', import.meta.url), 'utf8');
const script = component.match(/<script>([\s\S]*?)<\/script>/)?.[1];

function createHarness({ supportsModal = true } = {}) {
    const listeners = {};
    const alerts = [];
    const opener = { focused: 0, isConnected: true, focus() { this.focused += 1; } };
    const confirmationButton = { disabled: true };
    let dialog;

    class Control {
        constructor() { this.dataset = {}; this.textContent = ''; this.focused = 0; this.onclick = null; }
        focus() { this.focused += 1; }
    }

    class FakeDialog {
        constructor() {
            this.dataset = {};
            this.style = {};
            this.nodes = {
                '[data-title]': new Control(),
                '[data-message]': new Control(),
                '[data-cancel]': new Control(),
                '[data-ok]': new Control(),
            };
            this.open = false;
            if (supportsModal) this.showModal = () => { this.open = true; };
        }
        setAttribute() {}
        querySelector(selector) { return this.nodes[selector] || null; }
        close() {
            this.open = false;
            listeners.close?.({ target: this });
        }
    }

    const document = {
        activeElement: opener,
        addEventListener(type, handler) { listeners[type] = handler; },
        createElement() { dialog = new FakeDialog(); return dialog; },
        querySelectorAll(selector) { return selector === '[data-confirm-enable]' ? [confirmationButton] : []; },
        body: { appendChild() {} },
        getElementById(id) { return id === 'app-confirm-dialog' ? dialog : null; },
    };

    class HTMLFormElement {
        constructor() {
            this.dataset = { confirmMessage: 'Suspend Buyer account Alex (Account #7)?', confirmTitle: 'Suspend account', confirmLabel: 'Suspend Buyer', confirmDanger: 'true' };
            this.attributes = new Set(['data-app-confirm']);
            this.elements = { namedItem() { return null; } };
            this.submissions = [];
        }
        hasAttribute(name) { return this.attributes.has(name); }
        setAttribute(name) { this.attributes.add(name); }
        removeAttribute(name) { this.attributes.delete(name); }
        reportValidity() { return true; }
        requestSubmit(submitter) {
            this.submissions.push(submitter);
            listeners.submit({
                target: this,
                submitter,
                defaultPrevented: false,
                preventDefault() { this.defaultPrevented = true; },
            });
        }
    }

    runInNewContext(script, { document, window: { alert: message => alerts.push(message) }, HTMLFormElement });
    return { alerts, confirmationButton, dialog, getDialog: () => dialog, listeners, opener, HTMLFormElement };
}

test('confirmed submit buttons are enabled only when modal support initializes', () => {
    assert.equal(createHarness().confirmationButton.disabled, false);
    assert.equal(createHarness({ supportsModal: false }).confirmationButton.disabled, true);
});

test('confirmation cancel closes without submitting and restores focus', () => {
    const harness = createHarness();
    const form = new harness.HTMLFormElement();
    const submitter = { name: 'approve', value: 'yes', isConnected: true, focus() { this.focused = true; } };
    const event = { target: form, submitter, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };

    harness.listeners.submit(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(harness.getDialog().open, true);
    assert.equal(harness.getDialog().nodes['[data-title]'].textContent, 'Suspend account');
    assert.equal(harness.getDialog().nodes['[data-ok]'].textContent, 'Suspend Buyer');
    harness.getDialog().nodes['[data-cancel]'].onclick();
    assert.equal(form.submissions.length, 0);
    assert.equal(submitter.focused, true);
});

test('confirm resubmits exactly once with the original submitter', () => {
    const harness = createHarness();
    const form = new harness.HTMLFormElement();
    const submitter = { name: 'approve', value: 'yes' };
    const event = { target: form, submitter, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };

    harness.listeners.submit(event);
    harness.getDialog().nodes['[data-ok]'].onclick();
    assert.deepEqual(form.submissions, [submitter]);
    assert.equal(form.hasAttribute('data-app-confirm-bypass'), false);
});

test('confirmation fails closed when native modal dialog support is unavailable', () => {
    const harness = createHarness({ supportsModal: false });
    const form = new harness.HTMLFormElement();
    const event = { target: form, submitter: null, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };

    harness.listeners.submit(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(form.submissions.length, 0);
    assert.match(harness.alerts[0], /No action was submitted/);
});
