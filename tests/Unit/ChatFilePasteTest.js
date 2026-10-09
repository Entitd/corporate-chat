import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const template = readFileSync(new URL('../../resources/views/pages/⚡chat.blade.php', import.meta.url), 'utf8');
const handlers = template.slice(template.indexOf('        hasDraggedFiles(event) {'), template.indexOf('\n    }"\n    x-on:dragenter'))
    .replace(/@js\(__\('([^']*)'\)\)/g, (_, message) => JSON.stringify(message));

function composer(pendingFiles = [], activeChatId = 1) {
    const input = { files: null, dispatchEvent(event) { this.event = event; } };
    const wire = { pendingFiles, activeChatId, showChatList: false };
    const state = new Function('$wire', 'window', `return ({ ${handlers} });`)(wire, { innerWidth: 1280 });
    state.$el = { querySelector: () => input };
    state.showDropError = message => { state.error = message; };

    return { state, input };
}

function clipboard(files) {
    return { clipboardData: { files }, prevented: false, preventDefault() { this.prevented = true; } };
}

test('pasted files start the existing upload flow', () => {
    const { state, input } = composer();
    const event = clipboard([{ name: 'screenshot.png', size: 1024 }, { name: 'notes.txt', size: 100 }]);

    state.pasteFiles(event);

    assert.equal(event.prevented, true);
    assert.equal(input.files, event.clipboardData.files);
    assert.equal(input.event.type, 'change');
    assert.equal(input.event.bubbles, true);
});

test('text pastes keep the default browser behavior', () => {
    const { state, input } = composer();
    const event = clipboard([]);

    state.pasteFiles(event);
    state.pasteFiles({});

    assert.equal(event.prevented, false);
    assert.equal(input.files, null);
});

test('pasted files respect the total attachment limit', () => {
    const { state, input } = composer([{}, {}, {}]);

    state.pasteFiles(clipboard([{ size: 100 }]));

    assert.equal(input.files, null);
    assert.equal(state.error, 'Можно прикрепить не больше 3 файлов.');
});

test('oversized pasted files are rejected before uploading', () => {
    const { state, input } = composer();

    state.pasteFiles(clipboard([{ size: 2 * 1024 * 1024 + 1 }]));

    assert.equal(input.files, null);
    assert.equal(state.error, 'Файл должен быть не больше 2 МБ.');
});

test('files at the size limit can be added to existing attachments', () => {
    const { state, input } = composer([{}]);
    const event = clipboard([{ size: 2 * 1024 * 1024 }, { size: 100 }]);

    state.pasteFiles(event);

    assert.equal(input.files, event.clipboardData.files);
});

test('pasted files require an open chat', () => {
    const { state, input } = composer([], 0);

    state.pasteFiles(clipboard([{ size: 100 }]));

    assert.equal(input.files, null);
    assert.equal(state.error, 'Сначала откройте чат, в который хотите отправить файлы.');
});

test('dragged files still use the upload flow and clear the overlay', () => {
    const { state, input } = composer();
    const event = { ...clipboard([]), dataTransfer: { types: ['Files'], files: [{ size: 100 }] } };
    state.dragDepth = 2;
    state.draggingFiles = true;

    state.dropFiles(event);

    assert.equal(input.files, event.dataTransfer.files);
    assert.equal(state.dragDepth, 0);
    assert.equal(state.draggingFiles, false);
});
