import { test } from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = {};
const { attachmentPreview } = await import('../../resources/js/attachment-preview.js');

function viewer() {
    const state = attachmentPreview();
    state.kind = 'image';
    state.imageUrl = 'blob:test';
    state.$refs = {
        image: { naturalWidth: 2400, naturalHeight: 1600 },
        stage: { clientWidth: 832, clientHeight: 632, getBoundingClientRect: () => ({ left: 0, top: 0, width: 832, height: 632 }) },
    };
    state.fitImage();
    return state;
}

function pointer(id, x, y, type = 'touch') {
    return { pointerId: id, clientX: x, clientY: y, pointerType: type, currentTarget: { setPointerCapture() {} } };
}

test('photo fits the viewport and zoom keeps the point under the cursor stationary', () => {
    const state = viewer();
    assert.equal(state.imageWidth, 800);
    state.setImageZoom(2, 516, 316);
    assert.equal(state.panX, -100);
    state.panX = 9999;
    state.clampPan();
    assert.equal(state.panX, 384);
    state.setImageZoom(1);
    assert.equal(state.panX, 0);
    assert.equal(state.panY, 0);
});

test('dragging moves an enlarged photo and pinch changes its scale', () => {
    const state = viewer();
    state.setImageZoom(2);
    state.pointerDown(pointer(1, 400, 300));
    state.pointerMove(pointer(1, 450, 320));
    assert.equal(state.panX, 50);
    assert.equal(state.panY, 20);
    state.pointerDown(pointer(2, 550, 320));
    state.pointerMove(pointer(2, 650, 320));
    assert.equal(state.zoom, 4);
    state.pointerUp(pointer(2, 650, 320));
    state.pointerUp(pointer(1, 450, 320));
    assert.equal(state.dragging, false);
});

test('horizontal swipe navigates at fit scale; cancelled gestures do not navigate', () => {
    const state = viewer();
    const moves = [];
    state.navigatePhoto = delta => moves.push(delta);
    state.pointerDown(pointer(1, 300, 300));
    state.pointerMove(pointer(1, 100, 310));
    state.pointerUp(pointer(1, 100, 310));
    assert.deepEqual(moves, [1]);
    state.pointerDown(pointer(1, 300, 300));
    state.pointerUp({ ...pointer(1, 100, 300), type: 'pointercancel' });
    assert.deepEqual(moves, [1]);
});

test('gallery navigation respects its boundaries', () => {
    const state = viewer();
    state.gallery = [{ url: 'first' }, { url: 'second' }];
    state.photoIndex = 0;
    const opened = [];
    state.showPreview = (file, navigating) => opened.push([file.url, navigating]);
    state.navigatePhoto(-1);
    state.navigatePhoto(1);
    state.navigatePhoto(1);
    assert.deepEqual(opened, [['second', true]]);
});
