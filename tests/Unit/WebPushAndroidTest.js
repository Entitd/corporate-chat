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
    globalThis.window = { dispatchEvent() {}, isSecureContext: true, matchMedia: () => ({ matches: false }), PushManager: class {}, Notification: {} };
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

function existingSubscription(endpoint) {
    Notification.permission = 'granted';
    registration.pushManager.getSubscription = async () => ({ toJSON: () => ({ endpoint }), unsubscribe: async () => true });
}

test('banner menus and returning to chat share one subscription sync', async () => {
    existingSubscription('https://fcm.googleapis.com/shared-init');
    const states = [chatPushNotifications(), chatPushNotifications(), chatPushNotifications()];
    await Promise.all(states.map(state => state.init()));
    await chatPushNotifications().init();
    assert.equal(saved.length, 1);
    assert.ok(states.every(state => state.enabled && !state.busy));
});

test('a failed sync preserves the device status and can be retried on the next visit', async () => {
    existingSubscription('https://fcm.googleapis.com/failed-init');
    let calls = 0;
    globalThis.fetch = async () => { calls++; return { ok: false, status: 429 }; };
    const state = chatPushNotifications();
    await state.init();
    assert.equal(state.enabled, true);
    assert.equal(state.busy, false);
    assert.match(state.message, /не удалось обновить подписку/);
    await chatPushNotifications().init();
    assert.equal(calls, 2);
});

test('a new login resyncs the subscription instead of reusing the previous session', async () => {
    existingSubscription('https://fcm.googleapis.com/new-session');
    await chatPushNotifications().init();
    const query = document.querySelector;
    document.querySelector = selector => selector.includes('csrf') ? { content: 'new-csrf' } : query(selector);
    await chatPushNotifications().init();
    assert.equal(saved.length, 2);
});

test('initialization reads an existing subscription without updating its worker first', async () => {
    existingSubscription('https://fcm.googleapis.com/existing-worker');
    navigator.serviceWorker.getRegistration = async () => registration;
    navigator.serviceWorker.register = async () => { throw new Error('Worker update failed'); };
    const state = chatPushNotifications();
    await state.init();
    assert.equal(state.enabled, true);
    assert.equal(state.checked, true);
    assert.equal(state.message, '');
});

test('an unsuccessful device check cannot show an invitation to enable notifications', async () => {
    navigator.serviceWorker.getRegistration = async () => { throw new Error('Worker unavailable'); };
    const state = chatPushNotifications();
    await state.init();
    assert.equal(state.checked, false);
    assert.equal(state.busy, false);
    assert.ok(state.message);
    assert.equal(state.checked && !state.enabled && !state.busy, false);
});

test('a verified absent subscription still shows the enable banner even with granted permission', async () => {
    Notification.permission = 'granted';
    navigator.serviceWorker.getRegistration = async () => registration;
    const state = chatPushNotifications();
    await state.init();
    assert.equal(state.checked, true);
    assert.equal(state.enabled, false);
});
