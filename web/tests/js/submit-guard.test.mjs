import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/submit-guard.js', import.meta.url), 'utf8');

class FakeForm {
  constructor(method = 'post', attributes = {}) {
    this.method = method;
    this.attributes = { ...attributes };
    this.elements = [button(), button('button')];
  }
  hasAttribute(name) { return name in this.attributes; }
  setAttribute(name, value) { this.attributes[name] = value; }
  removeAttribute(name) { delete this.attributes[name]; }
}

function button(type = 'submit') {
  return { type, disabled: false, attributes: {},
    hasAttribute(name) { return name in this.attributes; },
    setAttribute(name, value) { this.attributes[name] = value; },
    removeAttribute(name) { delete this.attributes[name]; } };
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
  const submit = (form, prevented = false) => {
    const event = { target: form, defaultPrevented: prevented, preventDefault() { this.defaultPrevented = true; } };
    listeners.submit(event);
    return event;
  };
  const tick = () => timers.splice(0).forEach(({ callback }) => callback());
  return { submit, tick, timers, forms, listeners };
}

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

  assert.equal(submit(form).defaultPrevented, true);
});

test('does not lock when another handler cancelled the submit', () => {
  const { submit, tick } = mount();
  const form = new FakeForm();

  submit(form, true);
  tick();

  assert.equal(form.hasAttribute('data-submitting'), false);
  assert.equal(form.elements[0].disabled, false);
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
});

test('releases after the fallback delay and on back-forward restore', () => {
  const { submit, tick, timers, forms, listeners } = mount();
  const form = new FakeForm();
  form.elements[0].disabled = true; // already disabled by the page: must stay disabled
  form.elements.push(button());
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
});
