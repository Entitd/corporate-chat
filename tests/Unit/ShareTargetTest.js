import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

globalThis.window = {};
const { chatShareTarget } = await import('../../resources/js/share-target.js');
const origin = 'https://chat.example.test';
const id = '12345678-1234-1234-1234-123456789abc';
const key = `${origin}/chat/share-data/${id}`;

function storage() {
    const entries = new Map();
    const cache = {
        put: async (key, value) => { entries.set(String(key), value.clone()); },
        match: async key => entries.get(String(key))?.clone(),
        delete: async key => entries.delete(String(key)),
        keys: async () => [...entries.keys()],
    };
    return { entries, cache, caches: { open: async () => cache } };
}

function worker(store = storage()) {
    const handlers = {};
    const self = { location: { origin }, addEventListener: (name, handler) => { handlers[name] = handler; } };
    vm.runInNewContext(readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8'), {
        self, URL, Response, Date, caches: store.caches, crypto: { randomUUID: () => id },
    });
    return {
        ...store,
        receive(data, path = '/chat/share', method = 'POST') {
            let response;
            handlers.fetch({
                request: { url: origin + path, method, formData: async () => data },
                respondWith: promise => { response = promise; },
            });
            return response;
        },
    };
}

function sharedFile(size = 5) {
    const data = new FormData();
    data.append('files', new File([new Uint8Array(size)], 'report.pdf', { type: 'application/pdf' }));
    return data;
}

function screen(store, query = `share=${id}`, limit = 50) {
    window.location = { href: `${origin}/chat?${query}`, origin };
    window.history = { state: {}, replaceState: (_state, _unused, url) => { window.location.href = String(url); } };
    globalThis.caches = store.caches;
    const state = chatShareTarget();
    state.$el = { closest: () => ({ dataset: { maxFileSize: String(limit), fileSizeError: 'Файл слишком большой.' } }) };
    const uploads = [];
    state.$wire = {
        activeChatId: 0, showChatList: true, pendingFiles: [], messageBody: '',
        uploadMultiple: (name, files, finish, _error, progress) => {
            uploads.push({ name, files });
            progress({ detail: { progress: 100 } });
            state.$wire.pendingFiles.push(...files);
            finish();
        },
        $set: async (name, value) => { state.$wire[name] = value; },
    };
    return { state, uploads };
}

test('the manifest registers a multipart file and text share target', () => {
    const manifest = JSON.parse(readFileSync(new URL('../../public/manifest.webmanifest', import.meta.url)));
    assert.equal(manifest.share_target.action, '/chat/share');
    assert.equal(manifest.share_target.method, 'POST');
    assert.equal(manifest.share_target.enctype, 'multipart/form-data');
    assert.equal(manifest.share_target.params.files[0].name, 'files');
});

test('shared files survive navigation and are attached only after selecting a chat', async () => {
    const store = worker();
    const data = sharedFile();
    data.append('text', 'Документ');
    const response = await store.receive(data);
    assert.equal(response.status, 303);
    assert.equal(response.headers.get('Location'), `${origin}/chat?share=${id}`);
    const { state, uploads } = screen(store);
    await state.init();
    assert.equal(state.files[0].name, 'report.pdf');
    assert.equal(state.files[0].type, 'application/pdf');
    assert.equal(state.files[0].size, 5);
    assert.equal(state.text, 'Документ');
    await state.attach();
    assert.equal(uploads.length, 0);
    assert.equal(store.entries.size, 1);

    state.$wire.activeChatId = 7;
    state.$wire.showChatList = false;
    await state.attach();
    assert.equal(uploads.length, 1);
    assert.equal(uploads[0].name, 'pendingFiles');
    assert.equal(state.$wire.messageBody, 'Документ');
    assert.equal(state.visible, false);
    assert.equal(state.uploading, false);
    assert.equal(store.entries.size, 0);
    assert.equal(window.location.href, `${origin}/chat`);
});

test('the worker leaves other requests alone and rejects empty shares and too many files', async () => {
    const store = worker();
    assert.equal(store.receive(sharedFile(), '/chat'), undefined);
    assert.equal(store.receive(sharedFile(), '/chat/share', 'GET'), undefined);
    assert.equal((await store.receive(new FormData())).headers.get('Location'), `${origin}/chat?shareError=empty`);
    const data = sharedFile();
    for (let i = 0; i < 3; i++) data.append('files', new File(['x'], `${i}.txt`));
    assert.equal((await store.receive(data)).headers.get('Location'), `${origin}/chat?shareError=count`);
    assert.equal(store.entries.size, 0);
});

test('expired drafts are removed and missing drafts show an error', async () => {
    const store = worker();
    await store.cache.put('old', new Response('old', { headers: { 'X-Shared-At': '1' } }));
    await store.receive(sharedFile());
    assert.equal(store.entries.has('old'), false);
    await store.cache.put(key, new Response(sharedFile(), { headers: { 'X-Shared-At': '1' } }));
    const { state } = screen(store);
    await state.init();
    assert.match(state.error, /срок хранения истёк/);
    assert.equal(store.entries.size, 0);
});

test('configured file limits prevent uploads and keep the draft available for cancellation', async () => {
    const store = worker();
    await store.receive(sharedFile(51));
    const { state, uploads } = screen(store);
    await state.init();
    assert.equal(state.error, 'Файл слишком большой.');
    state.$wire.activeChatId = 7;
    state.$wire.showChatList = false;
    await state.attach();
    assert.equal(uploads.length, 0);
    assert.equal(store.entries.size, 1);
    await state.dismiss();
    assert.equal(store.entries.size, 0);
});

test('failed uploads retain files for retry without sending a message', async () => {
    const store = worker();
    await store.receive(sharedFile());
    const { state } = screen(store);
    await state.init();
    state.$wire.activeChatId = 7;
    state.$wire.showChatList = false;
    state.$wire.uploadMultiple = (_name, _files, _finish, error) => error();
    await state.attach();
    assert.match(state.error, /Не удалось загрузить/);
    assert.equal(state.uploading, false);
    assert.equal(state.visible, true);
    assert.equal(store.entries.size, 1);
});

test('text and links can be shared without a file upload', async () => {
    const store = worker();
    const data = new FormData();
    data.append('text', 'https://example.test/report');
    data.append('url', 'https://example.test/report');
    await store.receive(data);
    const { state, uploads } = screen(store);
    await state.init();
    state.$wire.activeChatId = 7;
    state.$wire.showChatList = false;
    await state.attach();
    assert.equal(uploads.length, 0);
    assert.equal(state.$wire.messageBody, 'https://example.test/report');
});

test('storage failure opens a recoverable error instead of posting to Laravel', async () => {
    const store = worker({ caches: { open: async () => { throw new Error('quota'); } } });
    const response = await store.receive(sharedFile());
    assert.equal(response.headers.get('Location'), `${origin}/chat?shareError=storage`);
});
