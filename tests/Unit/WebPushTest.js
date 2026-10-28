import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/webpush.js', import.meta.url), 'utf8').replace('export function', 'function');

function setup({ permission = 'granted', installed = true, ios = true, existing = false, responseOK = true } = {}) {
    const calls = [];
    const subscription = { toJSON: () => ({ endpoint: 'https://web.push.apple.com/test', keys: {} }), unsubscribe: async () => { calls.push('unsubscribe'); return true; } };
    const registration = { pushManager: {
        getSubscription: async () => existing ? subscription : null,
        subscribe: async options => { calls.push(['subscribe', options]); return subscription; },
    } };
    const window = { isSecureContext: true, PushManager: {}, Notification: {}, matchMedia: () => ({ matches: installed }) };
    const navigator = { userAgent: ios ? 'iPhone' : 'Chrome', platform: '', serviceWorker: {
        register: async () => { calls.push('register'); return registration; }, ready: Promise.resolve(registration),
    } };
    const Notification = { permission, requestPermission: () => { calls.push('permission'); return Promise.resolve(permission === 'default' ? 'granted' : permission); } };
    const document = { querySelector: selector => ({ content: selector.includes('public-key') ? 'B'.repeat(87) : selector.includes('csrf-token') ? 'csrf' : '/chat/push-subscriptions' }) };
    const fetch = async (url, options) => { calls.push(['fetch', url, options]); return { ok: responseOK }; };
    const state = new Function('window', 'navigator', 'document', 'Notification', 'fetch', 'atob', source + ';return chatPushNotifications();')(window, navigator, document, Notification, fetch, atob);
    return { state, calls, registration };
}

test('iPhone in a browser tab explains installation without requesting permission', async () => {
    const { state, calls } = setup({ installed: false });
    await state.init();
    await state.toggle();
    assert.match(state.message, /На экран Домой/);
    assert.deepEqual(calls, []);
});

test('enabling push requests permission first and stores the device with CSRF protection', async () => {
    const { state, calls } = setup({ permission: 'default' });
    await state.toggle();
    assert.equal(calls[0], 'permission');
    const request = calls.find(call => call[0] === 'fetch');
    assert.equal(request[2].method, 'POST');
    assert.equal(request[2].headers['X-CSRF-TOKEN'], 'csrf');
    assert.equal(state.enabled, true);
});

test('denied permission does not subscribe or save a device', async () => {
    const { state, calls } = setup({ permission: 'denied' });
    await state.toggle();
    assert.deepEqual(calls, ['permission']);
    assert.equal(state.enabled, false);
    assert.match(state.message, /запрещены/);
});

test('a failed server response does not report push as enabled', async () => {
    const { state } = setup({ responseOK: false });
    await state.toggle();
    assert.equal(state.enabled, false);
    assert.equal(state.busy, false);
    assert.match(state.message, /Не удалось сохранить/);
});

test('existing subscriptions are registered for the current account without a new permission prompt', async () => {
    const { state, calls } = setup({ existing: true });
    await state.init();
    assert.equal(state.enabled, true);
    assert.equal(calls.includes('permission'), false);
    assert.equal(calls.filter(call => call[0] === 'fetch').length, 1);
});

test('disabling push deletes the server subscription before unsubscribing the browser', async () => {
    const { state, calls, registration } = setup({ existing: true });
    state.enabled = true;
    state.registration = registration;
    await state.toggle();
    assert.equal(calls[0][2].method, 'DELETE');
    assert.equal(calls[1], 'unsubscribe');
    assert.equal(state.enabled, false);
});

function worker() {
    const handlers = {};
    const notifications = [];
    const opened = [];
    const self = {
        location: { origin: 'https://chat.example.test' },
        addEventListener: (name, handler) => { handlers[name] = handler; },
        registration: { showNotification: async (title, options) => notifications.push({ title, options }) },
        clients: { matchAll: async () => [], openWindow: async url => opened.push(url) },
    };
    vm.runInNewContext(readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8'), { self, URL });
    return { handlers, notifications, opened };
}

test('a push event displays an OS notification', async () => {
    const { handlers, notifications } = worker();
    let pending;
    handlers.push({ data: { json: () => ({ title: 'Новое сообщение', body: 'Откройте чат', url: '/chat?chat=7' }) }, waitUntil: promise => { pending = promise; } });
    await pending;
    assert.equal(notifications[0].title, 'Новое сообщение');
    assert.equal(notifications[0].options.data.url, '/chat?chat=7');
});

test('clicking a push opens its chat and rejects external navigation', async () => {
    const { handlers, opened } = worker();
    let pending;
    const event = url => ({ notification: { close() {}, data: { url } }, waitUntil: promise => { pending = promise; } });
    handlers.notificationclick(event('/chat?chat=7'));
    await pending;
    assert.deepEqual(opened, ['https://chat.example.test/chat?chat=7']);
    handlers.notificationclick(event('https://evil.test/chat'));
    assert.equal(opened.length, 1);
});
