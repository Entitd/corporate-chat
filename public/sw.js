self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

self.addEventListener('push', event => {
    let data = {};
    try { data = event.data?.json() ?? {}; } catch {}
    event.waitUntil(self.registration.showNotification(data.title || 'Новое сообщение', {
        body: data.body || 'Откройте чат, чтобы прочитать сообщение.',
        icon: '/apple-touch-icon.png',
        tag: data.tag,
        data: { url: data.url || '/chat' },
    }));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/chat', self.location.origin);
    if (target.origin !== self.location.origin || target.pathname !== '/chat') return;
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const existing = windows.find(client => new URL(client.url).pathname === '/chat');
        if (existing) {
            const navigated = await existing.navigate(target.href);
            if (navigated) return navigated.focus();
        }
        return self.clients.openWindow(target.href);
    })());
});
