import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const component = readFileSync(new URL('../../resources/views/Components/flash.blade.php', import.meta.url), 'utf8');
const script = component.match(/<script>([\s\S]*?)<\/script>/)?.[1];

test('dismiss control removes only its own flash message', () => {
    assert.ok(script, 'flash dismissal handler is present');
    let clickHandler;
    const document = {
        addEventListener(type, handler) {
            if (type === 'click') clickHandler = handler;
        },
    };
    runInNewContext(script, { document });

    const message = { removed: false, remove() { this.removed = true; } };
    const button = { closest: selector => selector === '[data-flash-message]' ? message : null };
    clickHandler({ target: { closest: selector => selector === '[data-flash-dismiss]' ? button : null } });
    assert.equal(message.removed, true);

    const otherMessage = { removed: false, remove() { this.removed = true; } };
    clickHandler({ target: { closest: () => null } });
    assert.equal(otherMessage.removed, false);
});
