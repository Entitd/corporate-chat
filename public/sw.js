self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

// Keep incoming shares on this device until the user chooses a chat and uploads.
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin || url.pathname !== '/chat/share' || event.request.method !== 'POST') return;

    event.respondWith((async () => {
        const redirect = query => Response.redirect(`${self.location.origin}/chat?${query}`, 303);
        try {
            const data = await event.request.formData();
            const files = data.getAll('files').filter(value => typeof value !== 'string');
            if (files.length > 3) return redirect('shareError=count');
            const text = ['text', 'url', 'title'].some(key => String(data.get(key) || '').trim());
            if (files.length === 0 && !text) return redirect('shareError=empty');

            const cache = await caches.open('chat-share-target-v1');
            for (const key of await cache.keys()) {
                const saved = await cache.match(key);
                if (Number(saved.headers.get('X-Shared-At')) < Date.now() - 60 * 60 * 1000) await cache.delete(key);
            }

            const id = crypto.randomUUID();
            await cache.put(`${self.location.origin}/chat/share-data/${id}`, new Response(data, {
                headers: { 'X-Shared-At': String(Date.now()) },
            }));
            return redirect(`share=${id}`);
        } catch {
            return redirect('shareError=storage');
        }
    })());
});

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
