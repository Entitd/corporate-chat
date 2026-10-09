import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const template = readFileSync(new URL('../../resources/views/pages/⚡chat.blade.php', import.meta.url), 'utf8');
const handler = template.slice(template.indexOf('        typeInMessage(event) {'), template.indexOf('        hasDraggedFiles(event) {'));

function composer({ editable = false, visible = true, missing = false, disabled = false, readOnly = false, dialog = false } = {}) {
    const input = {
        value: 'Черновик', disabled, readOnly,
        getClientRects: () => visible ? [{}] : [],
        focus() { this.focused = true; },
        setRangeText(text, start, end, selectionMode) {
            this.value = this.value.slice(0, start) + text + this.value.slice(end);
            this.selectionMode = selectionMode;
        },
        dispatchEvent(event) { this.event = event; },
    };
    const document = { querySelectorAll: () => [{ getClientRects: () => dialog ? [{}] : [] }] };
    const state = new Function('document', `return ({ ${handler} });`)(document);
    state.$el = { querySelector: () => missing ? null : input };
    const event = {
        key: 'я', target: { closest: () => editable ? {} : null },
        preventDefault() { this.defaultPrevented = true; },
    };

    return { state, input, event };
}

test('typing outside fields focuses the composer and preserves the first character and draft', () => {
    const { state, input, event } = composer();

    state.typeInMessage(event);

    assert.equal(input.value, 'Черновикя');
    assert.equal(input.focused, true);
    assert.equal(input.selectionMode, 'end');
    assert.equal(event.defaultPrevented, true);
    assert.equal(input.event.type, 'input');
    assert.equal(input.event.bubbles, true);
});

for (const key of ['A', ' ', '.', '😀']) {
    test(`printable character ${JSON.stringify(key)} starts a message`, () => {
        const { state, input, event } = composer();
        event.key = key;

        state.typeInMessage(event);

        assert.equal(input.value, 'Черновик' + key);
    });
}

for (const options of [{ editable: true }, { visible: false }, { missing: true }, { disabled: true }, { readOnly: true }, { dialog: true }]) {
    test(`typing is left alone when ${JSON.stringify(options)}`, () => {
        const { state, input, event } = composer(options);

        state.typeInMessage(event);

        assert.equal(input.value, 'Черновик');
        assert.equal(input.focused, undefined);
        assert.equal(event.defaultPrevented, undefined);
    });
}

for (const overrides of [{ ctrlKey: true }, { metaKey: true }, { altKey: true }, { isComposing: true }, { defaultPrevented: true }, ...['Enter', 'Escape', 'Tab', 'Backspace', 'ArrowDown', 'Dead'].map(key => ({ key }))]) {
    test(`shortcuts and non-text events are left alone: ${JSON.stringify(overrides)}`, () => {
        const { state, input, event } = composer();
        Object.assign(event, overrides);

        state.typeInMessage(event);

        assert.equal(input.value, 'Черновик');
        assert.equal(input.focused, undefined);
        assert.equal(input.event, undefined);
    });
}
