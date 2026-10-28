import assert from 'node:assert/strict';
import { beforeEach, test } from 'node:test';

globalThis.window = {};
const { chatPushNotifications } = await import('../../resources/js/webpush.js');
let registration;
let saved;
let subscribed;

beforeEach(() => {
    saved = [];
    subscribed = 0;
    const subscription = { toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/device', keys: {} }), unsubscribe: async () => true };
    registration = {
        pushManager: {
            getSubscription: async () => null,
            subscribe: async () => { subscribed++; return subscription; },
        },
    };
    globalThis.window = { isSecureContext: true, matchMedia: () => ({ matches: false }), PushManager: class {}, Notification: {} };
    globalThis.Notification = { permission: 'default', requestPermission: async () => 'granted' };
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: {
            userAgent: 'Android Opera', platform: 'Linux',
            serviceWorker: { register: async () => registration, ready: Promise.resolve(registration) },
        },
    });
    globalThis.document = {
        querySelector: selector => ({ content: selector.includes('public-key') ? 'BAAA' : selector.includes('csrf') ? 'token' : '/chat/push-subscriptions' }),
    };
    globalThis.fetch = async (url, options) => {
        saved.push({ url, options });
        return { ok: true, status: 204 };
    };
});

test('enabling push saves the device subscription and updates the button state', async () => {
    const state = chatPushNotifications();
    await state.toggle();
    assert.equal(state.enabled, true);
    assert.equal(state.busy, false);
    assert.match(state.message, /включены/);
    assert.equal(saved[0].url, '/chat/push-subscriptions');
    assert.equal(saved[0].options.method, 'POST');
    assert.equal(saved[0].options.headers['X-CSRF-TOKEN'], 'token');
    assert.equal(subscribed, 1);
});

test('callback permission requests work on mobile browser versions without a returned promise', async () => {
    Notification.requestPermission = callback => { queueMicrotask(() => callback('granted')); };
    const state = chatPushNotifications();
    await state.toggle();
    assert.equal(state.enabled, true);
});

test('HTTP reports the HTTPS requirement without requesting permission', async () => {
    window.isSecureContext = false;
    Notification.requestPermission = () => { throw new Error('Must not request'); };
    const state = chatPushNotifications();
    await state.toggle();
    assert.match(state.message, /HTTPS/);
    assert.equal(state.busy, false);
    assert.equal(saved.length, 0);
});

test('denied permissions show Android and site settings guidance', async () => {
    Notification.requestPermission = async () => 'denied';
    const state = chatPushNotifications();
    await state.toggle();
    assert.match(state.message, /Android/);
    assert.equal(state.enabled, false);
    assert.equal(state.busy, false);
    assert.equal(subscribed, 0);
});

for (const [status, expected] of [[419, /Сессия истекла/], [422, /422/], [503, /не настроены/]]) {
    test(`subscription error ${status} stays visible and does not mark push enabled`, async () => {
        globalThis.fetch = async () => ({ ok: false, status });
        const state = chatPushNotifications();
        await state.toggle();
        assert.match(state.message, expected);
        assert.equal(state.enabled, false);
        assert.equal(state.busy, false);
    });
}

test('a push service failure gives a readable error and releases the button', async () => {
    registration.pushManager.subscribe = async () => { throw new DOMException('Registration failed', 'AbortError'); };
    const state = chatPushNotifications();
    await state.toggle();
    assert.match(state.message, /службе push/);
    assert.equal(state.busy, false);
    assert.equal(state.enabled, false);
});

test('initialization reuses a granted subscription and disabling removes it', async () => {
    Notification.permission = 'granted';
    Notification.requestPermission = () => { throw new Error('Must not request twice'); };
    registration.pushManager.getSubscription = async () => ({ toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/device' }), unsubscribe: async () => true });
    const state = chatPushNotifications();
    await state.init();
    assert.equal(state.enabled, true);
    assert.equal(subscribed, 0);
    await state.toggle();
    assert.equal(state.enabled, false);
    assert.equal(saved[1].options.method, 'DELETE');
});

test('a stuck service worker releases the button after a timeout', async context => {
    context.mock.timers.enable({ apis: ['setTimeout'] });
    navigator.serviceWorker.register = () => new Promise(() => {});
    Notification.permission = 'granted';
    const state = chatPushNotifications();
    const enabling = state.toggle();
    assert.equal(state.busy, true);
    context.mock.timers.tick(20000);
    await enabling;
    assert.equal(state.busy, false);
    assert.equal(state.enabled, false);
    assert.match(state.message, /не смог загрузить/);
});
