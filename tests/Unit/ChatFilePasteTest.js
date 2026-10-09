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

function messageComposer({ editable = 'none', visible = true, dialog = false, disabled = false, readOnly = false, missing = false } = {}) {
    const { state, input: fileInput } = composer();
    const messageInput = {
        value: 'Черновик: ', disabled, readOnly,
        getClientRects: () => visible ? [{}] : [],
        focus() { this.focused = true; },
        setRangeText(text, start, end) { this.value = this.value.slice(0, start) + text + this.value.slice(end); },
        dispatchEvent(event) { this.event = event; },
    };
    const document = { querySelectorAll: () => [{ getClientRects: () => dialog ? [{}] : [] }] };
    const handlersWithDocument = new Function('$wire', 'window', 'document', `return ({ ${handlers} });`)(
        { pendingFiles: [], activeChatId: 1, showChatList: false }, { innerWidth: 1280 }, document,
    );
    Object.assign(state, handlersWithDocument);
    state.$el = { querySelector: selector => selector === '[data-test=message-input]' ? (missing ? null : messageInput) : fileInput };
    const event = {
        ...clipboard([]),
        clipboardData: { files: [], getData: () => 'Вставленный текст\nВторая строка' },
        target: { closest: () => editable === 'message' ? messageInput : editable === 'other' ? {} : null },
    };

    return { state, messageInput, fileInput, event };
}

test('pasting outside the message field appends text and synchronizes the editor', () => {
    const { state, messageInput, event } = messageComposer();

    state.pasteInMessage(event);

    assert.equal(messageInput.value, 'Черновик: Вставленный текст\nВторая строка');
    assert.equal(messageInput.focused, true);
    assert.equal(event.prevented, true);
    assert.equal(messageInput.event.type, 'input');
    assert.equal(messageInput.event.bubbles, true);
});

for (const editable of ['none', 'message']) {
    test(`pasting files from ${editable} uploads each clipboard batch once`, () => {
        const { state, messageInput, fileInput, event } = messageComposer({ editable });
        event.clipboardData.files = [{ name: 'screenshot.png', size: 100 }];

        state.pasteInMessage(event);

        assert.equal(event.prevented, true);
        assert.equal(messageInput.focused, true);
        assert.equal(fileInput.files, event.clipboardData.files);
        assert.equal(fileInput.event.type, 'change');
        assert.equal(messageInput.value, 'Черновик: ');
    });
}

test('text pasted into the focused message field keeps native selection and undo behavior', () => {
    const { state, messageInput, event } = messageComposer({ editable: 'message' });

    state.pasteInMessage(event);

    assert.equal(event.prevented, false);
    assert.equal(messageInput.event, undefined);
});

for (const options of [{ editable: 'other' }, { dialog: true }, { visible: false }, { disabled: true }, { readOnly: true }, { missing: true }]) {
    test(`pasting text or files is not intercepted when ${JSON.stringify(options)}`, () => {
        const { state, messageInput, fileInput, event } = messageComposer(options);

        state.pasteInMessage(event);
        event.clipboardData.files = [{ size: 100 }];
        state.pasteInMessage(event);

        assert.equal(event.prevented, false);
        assert.equal(messageInput.focused, undefined);
        assert.equal(fileInput.files, null);
    });
}

test('empty and already handled pastes do not change the editor', () => {
    const { state, messageInput, event } = messageComposer();
    event.defaultPrevented = true;
    state.pasteInMessage(event);
    event.defaultPrevented = false;
    event.clipboardData = undefined;

    state.pasteInMessage(event);

    assert.equal(messageInput.value, 'Черновик: ');
    assert.equal(event.prevented, false);
    assert.equal(messageInput.focused, undefined);
});

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
