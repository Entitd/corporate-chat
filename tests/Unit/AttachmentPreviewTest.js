import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/attachment-preview.js', import.meta.url), 'utf8').replace('export function', 'function');

function setup(fetch) {
    const revoked = [];
    const urls = [];
    const window = {};
    const url = {
        createObjectURL: () => { const next = `blob:preview-${urls.length}`; urls.push(next); return next; },
        revokeObjectURL: value => revoked.push(value),
    };
    const state = new Function('window', 'fetch', 'URL', 'document', source + '; return attachmentPreview();')(window, fetch, url, { querySelectorAll: () => [] });
    state.$refs = {
        dialog: { open: false, showModal() { this.open = true; }, close() { this.open = false; } },
        canvas: { width: 0, height: 0 },
    };
    return { state, revoked, urls };
}

const image = () => ({ ok: true, headers: new Headers({ 'Content-Type': 'image/png' }), arrayBuffer: async () => new ArrayBuffer(1) });
const file = { url: '/preview/1', name: 'photo.png', download: '/download/1' };

test('closing an image preview releases its object URL', async () => {
    const { state, revoked } = setup(async () => image());
    await state.showPreview(file);
    assert.equal(state.kind, 'image');
    assert.equal(state.$refs.dialog.open, true);
    const url = state.imageUrl;
    state.closePreview();
    assert.equal(state.$refs.dialog.open, false);
    assert.equal(state.imageUrl, '');
    assert.deepEqual(revoked, [url]);
});

test('a response for a closed file cannot replace a newly opened preview', async () => {
    let finish;
    const { state, urls } = setup(url => url === '/preview/1' ? new Promise(resolve => { finish = resolve; }) : Promise.resolve(image()));
    const first = state.showPreview(file);
    state.closePreview();
    await state.showPreview({ ...file, url: '/preview/2', name: 'second.png' });
    const currentUrl = state.imageUrl;
    finish(image());
    await first;
    assert.equal(state.name, 'second.png');
    assert.equal(state.imageUrl, currentUrl);
    assert.equal(state.$refs.dialog.open, true);
    assert.equal(urls.length, 1);
    state.destroy();
});

test('denied preview requests show a readable error and preserve the download link', async () => {
    const { state } = setup(async () => ({ ok: false, status: 403 }));
    await state.showPreview(file);
    assert.match(state.error, /Файл недоступен/);
    assert.equal(state.download, file.download);
    assert.equal(state.loading, false);
});

test('closing a loading preview aborts its request without showing an error', async () => {
    let signal;
    const { state } = setup((url, options) => {
        signal = options.signal;
        return new Promise((resolve, reject) => signal.addEventListener('abort', () => reject(new DOMException('Closed', 'AbortError'))));
    });
    const opening = state.showPreview(file);
    state.closePreview();
    await opening;
    assert.equal(signal.aborted, true);
    assert.equal(state.loading, false);
    assert.equal(state.error, '');
});
