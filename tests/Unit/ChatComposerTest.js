import assert from 'node:assert/strict';
import test from 'node:test';

globalThis.window = {};
const { chatComposer } = await import('../../resources/js/chat-composer.js');

function composer() {
    const requests = [];
    const state = chatComposer();
    state.$nextTick = callback => callback();
    state.$wire = { showMentionPicker: false, $set: (...args) => requests.push(args) };
    return { state, requests };
}

test('ordinary typing stays local until a message is sent', () => {
    const { state, requests } = composer();
    for (const text of ['Привет', 'Привет, команда!', 'Почта: name@example.test']) state.syncMentionSearch(text);
    assert.deepEqual(requests, []);
});

test('stacking action buttons cannot collapse text that only fits at the wider width', () => {
    const { state } = composer();
    globalThis.getComputedStyle = () => ({ columnGap: '2px' });
    let short = false;
    const input = {
        style: { width: '', flex: '' },
        getClientRects: () => [{}],
        getBoundingClientRect: () => ({ width: state.stackActions ? 242 : 200 }),
        get scrollHeight() {
            const width = parseFloat(this.style.width) || (state.stackActions ? 242 : 200);
            return short || width > 220 ? 42 : 64;
        },
    };
    const actions = {
        querySelectorAll: () => [0, 1].map(() => ({ getBoundingClientRect: () => ({ width: 40 }) })),
        getBoundingClientRect: () => ({ width: state.stackActions ? 40 : 82 }),
    };
    state.$refs = { messageInput: input, composerActions: actions };
    for (let i = 0; i < 5; i++) {
        state.resizeInput();
        assert.equal(state.stackActions, true);
        assert.equal(state.inputHeight, 88);
    }
    short = true;
    state.resizeInput();
    assert.equal(state.stackActions, false);
    assert.equal(state.inputHeight, 44);
});

test('typing a mention requests server suggestions for Cyrillic and Latin names', () => {
    const { state, requests } = composer();
    for (const text of ['@', 'Привет, @Де', '@alex_1']) state.syncMentionSearch(text);
    assert.equal(requests.length, 3);
    assert.deepEqual(requests[1], ['messageBody', 'Привет, @Де']);
});

test('leaving an active mention closes the server suggestion picker', () => {
    const { state, requests } = composer();
    state.$wire.showMentionPicker = true;
    state.syncMentionSearch('Привет, команда ');
    assert.deepEqual(requests, [['messageBody', 'Привет, команда ']]);
});

test('multiline input grows within its height limit and shrinks after clearing', () => {
    const { state } = composer();
    const input = { style: {}, scrollHeight: 108, getClientRects: () => [{}] };
    state.$refs = { messageInput: input };
    state.resizeInput();
    assert.equal(input.style.height, '110px');
    input.scrollHeight = 400;
    state.resizeInput();
    assert.equal(input.style.height, '160px');
    input.scrollHeight = 64;
    state.resizeInput();
    assert.equal(input.style.height, '88px');
    input.scrollHeight = 42;
    state.resizeInput();
    assert.equal(input.style.height, '44px');
});

test('hidden input keeps its height instead of resizing from zero measurements', () => {
    const { state } = composer();
    const input = { style: { height: '132px' }, scrollHeight: 0, getClientRects: () => [] };
    state.$refs = { messageInput: input };
    state.resizeInput();
    assert.equal(input.style.height, '132px');

    input.getClientRects = () => [{}];
    input.scrollHeight = 64;
    state.resizeInput();
    assert.equal(input.style.height, '88px');
});

test('clicking short input keeps one line and height follows text changes', () => {
    const { state } = composer();
    const input = { style: {}, scrollHeight: 42, getClientRects: () => [{}] };
    state.$refs = { messageInput: input };
    state.resizeInput();
    assert.equal(input.style.height, '44px');

    state.resizeInput();
    assert.equal(input.style.height, '44px');
    assert.equal(state.inputHeight, 44);

    input.scrollHeight = 64;
    state.resizeInput();
    assert.equal(input.style.height, '88px');
    assert.equal(state.inputHeight, 88);

    input.getClientRects = () => [];
    input.scrollHeight = 0;
    state.resizeInput();
    assert.equal(input.style.height, '88px');

    input.getClientRects = () => [{}];
    input.scrollHeight = 42;
    state.resizeInput();
    assert.equal(input.style.height, '44px');
    assert.equal(state.inputHeight, 44);
});
