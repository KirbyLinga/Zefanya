import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/submit-guard.js', import.meta.url), 'utf8');

const DEFAULT_LABEL = 'Working…';
const CUSTOM_LABEL = 'Submitting...';

function attributeName(property) {
  return 'data-' + String(property).replace(/[A-Z]/g, (character) => '-' + character.toLowerCase());
}

// Minimal dataset shim: same camelCase ⇄ data-* mapping the real DOM uses.
function datasetFor(element) {
  const missing = Symbol('missing');
  const read = (property) => {
    const value = element.getAttribute(attributeName(property));
    return value === null ? undefined : value;
  };
  return new Proxy({}, {
    get: (_, property) => (typeof property === 'string' ? read(property) : missing),
    set: (_, property, value) => { element.setAttribute(attributeName(property), String(value)); return true; },
    deleteProperty: (_, property) => { element.removeAttribute(attributeName(property)); return true; },
    has: (_, property) => element.hasAttribute(attributeName(property)),
  });
}

class FakeButton {
  constructor({ type = 'submit', attributes = {}, html = '' } = {}) {
    this.type = type;
    this.disabled = false;
    this.attributes = { ...attributes };
    this.innerHTML = html;
    this.dataset = datasetFor(this);
  }

  hasAttribute(name) { return name in this.attributes; }
  getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  removeAttribute(name) { delete this.attributes[name]; }
}

class FakeForm {
  constructor(method = 'post', attributes = {}, elements = []) {
    this.method = method;
    this.attributes = { ...attributes };
    this.elements = elements.length ? elements : [new FakeButton(), new FakeButton({ type: 'button' })];
    this.dataset = datasetFor(this);
  }

  hasAttribute(name) { return name in this.attributes; }
  getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  removeAttribute(name) { delete this.attributes[name]; }
}

function mount() {
  const listeners = {};
  const timers = [];
  const forms = [];
  const context = {
    HTMLFormElement: FakeForm,
    document: {
      addEventListener: (name, handler) => { listeners[name] = handler; },
      querySelectorAll: () => forms.filter(form => form.hasAttribute('data-submitting')),
    },
    window: { addEventListener: (name, handler) => { listeners[`window:${name}`] = handler; } },
    setTimeout: (callback, ms) => { timers.push({ callback, ms }); },
  };
  runInNewContext(source, context);
  const submit = (form, { prevented = false, submitter = undefined } = {}) => {
    const event = { target: form, defaultPrevented: prevented, submitter,
      preventDefault() { this.defaultPrevented = true; } };
    listeners.submit(event);
    return event;
  };
  const tick = () => timers.splice(0).forEach(({ callback }) => callback());
  return { submit, tick, timers, forms, listeners };
}

test('shows a spinner and the default Working label on the initiating button', () => {
  const { submit, tick } = mount();
  const form = new FakeForm();

  submit(form);
  tick();

  const html = form.elements[0].innerHTML;
  assert.match(html, /zf-submit-spinner/, 'a spinner is rendered');
  assert.match(html, new RegExp(DEFAULT_LABEL.replace('.', '\\.')), 'the default label is shown');
  assert.equal(form.elements[0].disabled, true);
  assert.equal(form.getAttribute('aria-busy'), 'true', 'the form is marked busy');
});

test('uses data-submit-loading when the button provides one', () => {
  const { submit, tick } = mount();
  const publish = new FakeButton({ attributes: { 'data-submit-loading': CUSTOM_LABEL }, html: 'Publish' });
  const form = new FakeForm('post', {}, [publish]);

  submit(form);
  tick();

  assert.match(publish.innerHTML, /Submitting\.\.\./);
  assert.doesNotMatch(publish.innerHTML, new RegExp(DEFAULT_LABEL.replace('.', '\\.')));
});

test('restores the original markup and accessible name on release', () => {
  const { submit, tick } = mount();
  const original = '<i data-lucide="check"></i> Save changes';
  const save = new FakeButton({ attributes: { 'aria-label': 'Save changes' }, html: original });
  const form = new FakeForm('post', {}, [save]);

  submit(form);
  tick();
  assert.match(save.innerHTML, /zf-submit-spinner/);
  assert.equal(save.getAttribute('aria-label'), `Save changes — ${DEFAULT_LABEL}`);

  tick(); // fallback release
  assert.equal(save.innerHTML, original, 'icons and text come back exactly');
  assert.equal(save.getAttribute('aria-label'), 'Save changes');
  assert.equal(save.disabled, false);
  assert.equal(form.hasAttribute('data-submitting'), false);
  assert.equal(form.hasAttribute('aria-busy'), false);
});

test('locks a POST form after the first submit and blocks the second', () => {
  const { submit, tick } = mount();
  const form = new FakeForm();

  const first = submit(form);
  assert.equal(first.defaultPrevented, false);
  assert.equal(form.elements[0].disabled, false, 'button stays enabled while the browser builds form data');
  tick();
  assert.equal(form.hasAttribute('data-submitting'), true);
  assert.equal(form.elements[0].disabled, true);
  assert.equal(form.elements[1].disabled, false, 'non-submit buttons are left alone');

  const htmlWhileLoading = form.elements[0].innerHTML;
  assert.equal(submit(form).defaultPrevented, true);
  assert.equal(form.elements[0].innerHTML, htmlWhileLoading, 'a blocked repeat does not restart the spinner');

  tick(); // the queued fallback release still restores the button
  assert.equal(form.elements[0].innerHTML, '', 'original (empty) markup comes back');
  assert.equal(form.elements[0].disabled, false);
});

test('does not lock when another handler cancelled the submit', () => {
  const { submit, tick } = mount();
  const form = new FakeForm();

  submit(form, { prevented: true });
  tick();

  assert.equal(form.hasAttribute('data-submitting'), false);
  assert.equal(form.elements[0].disabled, false);
  assert.equal(form.elements[0].innerHTML, '', 'no spinner is shown');
  assert.equal(submit(form).defaultPrevented, false, 'a later real submit still goes through');
});

test('ignores GET forms and forms that opt out', () => {
  const { submit, tick } = mount();
  const search = new FakeForm('get');
  const download = new FakeForm('post', { 'data-allow-resubmit': '' });

  submit(search);
  submit(download);
  tick();

  assert.equal(search.hasAttribute('data-submitting'), false);
  assert.equal(download.hasAttribute('data-submitting'), false);
  assert.equal(search.elements[0].innerHTML, '');
  assert.equal(download.elements[0].innerHTML, '');
});

test('spins only the button that submitted the form', () => {
  const { submit, tick } = mount();
  const draft = new FakeButton({ attributes: { 'data-submit-loading': 'Saving draft...' }, html: 'Save as draft' });
  const publish = new FakeButton({ html: 'Publish' });
  const form = new FakeForm('post', {}, [draft, publish]);

  submit(form, { submitter: publish });
  tick();

  assert.match(publish.innerHTML, /zf-submit-spinner/);
  assert.equal(draft.innerHTML, 'Save as draft', 'the other action keeps its own label');
  assert.equal(draft.disabled, true, 'but it cannot be clicked while one action runs');

  tick(); // release
  assert.equal(draft.disabled, false);
  assert.equal(publish.innerHTML, 'Publish');
});

test('falls back to the single enabled button when the browser sends no submitter', () => {
  const { submit, tick } = mount();
  const form = new FakeForm();

  submit(form);
  tick();

  assert.match(form.elements[0].innerHTML, /zf-submit-spinner/);
  assert.equal(form.elements[1].innerHTML, '');
});

test('does not spin any button when several actions could have submitted', () => {
  const { submit, tick } = mount();
  const first = new FakeButton({ html: 'Approve' });
  const second = new FakeButton({ html: 'Reject' });
  const form = new FakeForm('post', {}, [first, second]);

  submit(form); // no submitter information available
  tick();

  assert.equal(first.innerHTML, 'Approve');
  assert.equal(second.innerHTML, 'Reject');
  assert.equal(first.disabled, true, 'the form still locks against duplicates');
  assert.equal(second.disabled, true);
});

test('leaves the button untouched when a confirmation dialog is cancelled', () => {
  const { submit, tick } = mount();
  const accept = new FakeButton({ html: 'Accept order' });
  const form = new FakeForm('post', {}, [accept]);

  // appConfirmSubmit() only calls form.requestSubmit() after the user confirms,
  // so a cancelled prompt dispatches no submit event at all.
  assert.equal(accept.innerHTML, 'Accept order');
  assert.equal(accept.disabled, false);

  submit(form, { submitter: accept });
  tick();
  assert.match(accept.innerHTML, /zf-submit-spinner/);
});

test('releases after the fallback delay and on back-forward restore', () => {
  const { submit, tick, timers, forms, listeners } = mount();
  const form = new FakeForm();
  form.elements[0].disabled = true; // already disabled by the page: must stay disabled
  form.elements.push(new FakeButton());
  forms.push(form);

  submit(form);
  tick();
  assert.equal(timers.length, 1);
  assert.equal(timers[0].ms, 8000);
  tick();
  assert.equal(form.hasAttribute('data-submitting'), false);
  assert.equal(form.elements[0].disabled, true, 'page-disabled button is not re-enabled');
  assert.equal(form.elements[2].disabled, false);

  submit(form);
  tick();
  listeners['window:pageshow']({ persisted: true });
  assert.equal(form.hasAttribute('data-submitting'), false);
  assert.equal(form.elements[2].disabled, false);
  assert.equal(form.elements[2].innerHTML, '', 'the pristine markup comes back');
});
