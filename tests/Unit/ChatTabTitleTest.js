import assert from 'node:assert/strict';
import test from 'node:test';

globalThis.window = {};
const { watchChatTabTitle } = await import('../../resources/js/chat-tab-title.js');

function tab(count = 0) {
    globalThis.document = { title: 'Чат — Компания' };
    let onChange;
    let disconnected = false;
    globalThis.MutationObserver = class {
        constructor(callback) { onChange = callback; }
        observe() {}
        disconnect() { disconnected = true; }
    };
    const element = { dataset: { unreadCount: String(count) } };
    const destroy = watchChatTabTitle(element);

    return {
        destroy,
        disconnected: () => disconnected,
        unread(count) {
            element.dataset.unreadCount = String(count);
            onChange();
        },
    };
}

test('the tab shows existing unread messages and tracks incoming messages and reads', () => {
    const state = tab(1);
    assert.equal(document.title, 'Новое сообщение (1) — Чат — Компания');
    state.unread(3);
    assert.equal(document.title, 'Новые сообщения (3) — Чат — Компания');
    state.unread(1);
    assert.equal(document.title, 'Новое сообщение (1) — Чат — Компания');
    state.unread(0);
    assert.equal(document.title, 'Чат — Компания');
    state.destroy();
});

test('a tab without unread messages keeps its title and restores it when leaving the chat', () => {
    const state = tab();
    assert.equal(document.title, 'Чат — Компания');
    state.unread(2);
    state.destroy();
    assert.equal(document.title, 'Чат — Компания');
    assert.equal(state.disconnected(), true);
});

test('cleanup preserves a new page title set during navigation', () => {
    const state = tab(2);
    document.title = 'Настройки — Компания';
    state.destroy();
    assert.equal(document.title, 'Настройки — Компания');
    assert.equal(state.disconnected(), true);
});
