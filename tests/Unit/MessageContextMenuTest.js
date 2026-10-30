import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/message-context-menu.js', import.meta.url), 'utf8').replace('export function', 'function');

function setup() {
    const timers = new Map();
    let id = 0;
    const state = new Function('window', 'setTimeout', 'clearTimeout', source + '; return messageContextMenu();')(
        {}, (callback, delay) => { assert.equal(delay, 500); timers.set(++id, callback); return id; }, key => timers.delete(key),
    );
    const focused = [];
    const dropdown = { value: false, querySelector: () => ({ focus: options => focused.push(options) }) };
    // The event's $el is the bubble; the dropdown is its sibling.
    state.$el = { querySelector: () => null };
    state.$refs = { messageDropdown: dropdown };
    const fire = () => { const pending = [...timers.values()]; timers.clear(); pending.forEach(callback => callback()); };
    return { state, dropdown, focused, fire, timers };
}
const touch = (x = 10, y = 20) => ({ pointerType: 'touch', isPrimary: true, pointerId: 1, clientX: x, clientY: y });

test('right click opens the existing actions menu without scrolling to its button', () => {
    const { state, dropdown, focused } = setup();
    let prevented = false;
    state.openMessageMenu({ preventDefault: () => prevented = true });
    assert.equal(prevented, true);
    assert.equal(dropdown.value, true);
    assert.deepEqual(focused, [{ preventScroll: true }]);
});

test('long press opens menu and consumes the click so a photo or link does not open', () => {
    const { state, dropdown, fire } = setup();
    state.startMessagePress(touch());
    assert.equal(dropdown.value, false);
    fire();
    state.cancelMessagePress();
    assert.equal(dropdown.value, true);
    let blocked = 0;
    state.messageContextClick({ preventDefault: () => blocked++, stopImmediatePropagation: () => blocked++ });
    assert.equal(blocked, 2);
    state.messageContextClick({ preventDefault: () => blocked++, stopImmediatePropagation: () => blocked++ });
    assert.equal(blocked, 2);
});

test('scroll movement, a short tap, pointer cancellation and cleanup cancel long press', () => {
    for (const cancel of [state => state.moveMessagePress(touch(10, 45)), state => state.cancelMessagePress(), state => state.destroy()]) {
        const { state, dropdown, fire, timers } = setup();
        state.startMessagePress(touch());
        cancel(state);
        assert.equal(timers.size, 0);
        fire();
        assert.equal(dropdown.value, false);
    }
});

test('mouse presses and additional fingers do not start a long press', () => {
    const { state, timers } = setup();
    state.startMessagePress({ ...touch(), pointerType: 'mouse' });
    assert.equal(timers.size, 0);
    state.startMessagePress(touch());
    state.startMessagePress({ ...touch(), isPrimary: false, pointerId: 2 });
    assert.equal(timers.size, 0);
});
