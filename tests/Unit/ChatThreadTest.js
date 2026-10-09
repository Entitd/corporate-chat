import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const template = readFileSync(new URL('../../resources/views/components/chat/thread.blade.php', import.meta.url), 'utf8');
const expression = template.match(/x-data="([\s\S]*?)"\s+x-init=/)[1];
const settle = () => new Promise(resolve => setImmediate(resolve));

function thread() {
    const reads = [];
    const messages = [];
    const element = {
        dataset: { chatId: '1', hasUnread: 'true', searching: 'false', lastReadMessageId: '1' },
        scrollTop: 700, clientHeight: 300, scrollHeight: 1000,
        getClientRects: () => [{}],
        getBoundingClientRect: () => ({ top: 0, bottom: 300 }),
        querySelectorAll: () => messages,
    };
    const document = { visibilityState: 'visible', hasFocus: () => true };
    const wire = { markOpenChatAsRead: (...args) => { reads.push(args); return Promise.resolve(); } };
    const state = new Function('$el', '$wire', 'document', `return (${expression});`)(element, wire, document);
    const ticks = [];
    state.$nextTick = callback => ticks.push(callback);
    const message = (id, top, bottom) => ({
        dataset: { chatMessageId: String(id) },
        getBoundingClientRect: () => ({ top, bottom }),
    });

    return { state, element, document, reads, messages, message, render: () => ticks.splice(0).forEach(callback => callback()) };
}

test('a new incoming message scrolls to the bottom after rendering when already following', async () => {
    const { state, element, messages, message, reads, render } = thread();
    state.handleScroll();

    state.handleIncomingMessage({ detail: { chatId: 1, messageId: 2 } });
    element.scrollHeight = 1100;
    messages.push(message(2, 200, 290));
    render();
    await settle();

    assert.equal(element.scrollTop, 1100);
    assert.deepEqual(reads, [[2, false]]);
});

test('incoming messages preserve the position while reading history', () => {
    const { state, element, reads, render } = thread();
    element.scrollTop = 100;
    state.handleScroll();

    state.handleIncomingMessage({ detail: { chatId: 1, messageId: 2 } });
    render();

    assert.equal(element.scrollTop, 100);
    assert.deepEqual(reads, []);
});

test('messages from another chat and search results do not scroll the thread', () => {
    const { state, element, render } = thread();
    state.followingNewMessages = true;

    state.handleIncomingMessage({ detail: { chatId: 2, messageId: 2 } });
    element.dataset.searching = 'true';
    state.handleIncomingMessage({ detail: { chatId: 1, messageId: 3 } });
    render();

    assert.equal(element.scrollTop, 700);
});

test('opening an unread conversation preserves the last read position', () => {
    const { state, element, messages, message } = thread();
    element.scrollTop = 0;
    messages.push(message(1, 0, 90), message(2, 100, 190));

    state.scrollToOpeningPosition();

    assert.equal(element.scrollTop, 0);
});

test('mouse activity reads only visible messages without scrolling or repeated requests', async () => {
    const { state, element, messages, message, reads } = thread();
    messages.push(message(1, -90, -10), message(2, 10, 100), message(3, 310, 400));

    state.readVisibleMessages();
    state.readVisibleMessages();
    await settle();
    state.readVisibleMessages();

    assert.deepEqual(reads, [[2, false]]);
    assert.equal(element.scrollTop, 700);
});

test('hidden, unfocused, and searching chats do not mark messages as read', () => {
    for (const condition of ['hidden', 'unfocused', 'searching', 'closed']) {
        const { state, element, document, messages, message, reads } = thread();
        messages.push(message(2, 10, 100));
        if (condition === 'hidden') document.visibilityState = 'hidden';
        if (condition === 'unfocused') document.hasFocus = () => false;
        if (condition === 'searching') element.dataset.searching = 'true';
        if (condition === 'closed') element.getClientRects = () => [];

        state.readVisibleMessages();

        assert.deepEqual(reads, []);
    }
});

test('a message arriving during a read request is marked once that request finishes', async () => {
    const { state, messages, message, reads } = thread();
    messages.push(message(2, 10, 100));

    state.readVisibleMessages();
    messages.push(message(3, 110, 200));
    state.readVisibleMessages();
    await settle();

    assert.deepEqual(reads, [[2, false], [3, false]]);
});

test('scrolling upward immediately stops following incoming messages', () => {
    const { state, element } = thread();
    state.followingNewMessages = true;
    element.scrollTop = 500;

    state.handleScroll();

    assert.equal(state.followingNewMessages, false);
});
