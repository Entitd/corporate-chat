// Reuse subscription syncs across the banner, both menus and wire:navigate visits.
const subscriptionSyncs = new Map();
const syncLifetime = 5 * 60 * 1000;

function withTimeout(operation, message, milliseconds = 20000) {
    let timer;
    return Promise.race([
        operation,
        new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(message)), milliseconds); }),
    ]).finally(() => clearTimeout(timer));
}

export function chatPushNotifications() {
    return {
        busy: false,
        enabled: false,
        checked: false,
        message: '',
        registration: null,
        publicKey: document.querySelector('meta[name="webpush-public-key"]')?.content,
        endpoint: document.querySelector('meta[name="webpush-subscription-url"]')?.content,
        installed: window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true,
        isIOS: /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1),
        get needsInstallation() { return this.isIOS && !this.installed; },
        get supported() {
            return window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        },
        async init() {
            if (this.needsInstallation || !this.supported || !this.publicKey) {
                this.checked = true;
                return;
            }
            this.busy = true;
            this.message = '';
            try {
                // Read the existing worker first: a failed worker update must not
                // prevent us from finding a subscription that already works.
                this.registration = await withTimeout(
                    navigator.serviceWorker.getRegistration?.('/') ?? Promise.resolve(null),
                    'Не удалось проверить уведомления. Попробуйте ещё раз.');
                if (!this.registration) await this.prepareRegistration();
                const subscription = await this.currentSubscription();
                this.enabled = !!subscription && Notification.permission === 'granted';
                this.checked = true;
                if (this.enabled) await this.syncSubscription(subscription);
            } catch (error) {
                this.message = this.enabled
                    ? 'Уведомления разрешены на устройстве, но не удалось обновить подписку на сервере. Попробуйте позже.'
                    : this.errorMessage(error);
            } finally { this.busy = false; }
        },
        syncSubscription(subscription) {
            // CSRF changes on a new login; the server must bind the subscription to that session.
            const key = JSON.stringify([this.endpoint, document.querySelector('meta[name="csrf-token"]').content, subscription.toJSON()]);
            const cached = subscriptionSyncs.get(key);
            if (cached && cached.expiresAt > Date.now()) return cached.promise;

            const entry = { expiresAt: Infinity, promise: null };
            entry.promise = this.save(subscription).then(() => {
                entry.expiresAt = Date.now() + syncLifetime;
            }).catch(error => {
                if (subscriptionSyncs.get(key) === entry) subscriptionSyncs.delete(key);
                throw error;
            });
            subscriptionSyncs.set(key, entry);
            return entry.promise;
        },
        async prepareRegistration() {
            await withTimeout(navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' }),
                'Браузер не смог загрузить службу уведомлений. Проверьте соединение и обновите страницу.');
            this.registration = await withTimeout(navigator.serviceWorker.ready,
                'Служба уведомлений не запускается. Обновите страницу и попробуйте снова.');
        },
        currentSubscription() {
            return withTimeout(this.registration.pushManager.getSubscription(),
                'Браузер не отвечает при проверке подписки. Обновите страницу и попробуйте снова.');
        },
        errorMessage(error) {
            if (error?.name === 'NotAllowedError' || (typeof Notification !== 'undefined' && Notification.permission === 'denied')) {
                return 'Уведомления запрещены. Разрешите их для этого сайта в браузере и для Opera в настройках Android → Приложения → Opera → Уведомления.';
            }
            if (error?.name === 'NotSupportedError') {
                return 'Эта версия браузера не поддерживает push-уведомления. Обновите Opera или откройте чат в Chrome.';
            }
            if (error?.name === 'AbortError') {
                return 'Браузер не смог подключиться к службе push-уведомлений. Проверьте соединение; если включён VPN, попробуйте без него.';
            }
            return error?.message || 'Не удалось включить уведомления. Попробуйте снова.';
        },
        async save(subscription, method = 'POST') {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 20000);
            let response;
            try {
                response = await fetch(this.endpoint, {
                    method,
                    signal: controller.signal,
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(subscription.toJSON()),
                });
            } catch (error) {
                if (controller.signal.aborted) throw new Error('Сервер не ответил за 20 секунд. Проверьте соединение и попробуйте снова.');
                throw error;
            } finally { clearTimeout(timer); }
            if ([401, 403, 419].includes(response.status)) throw new Error('Сессия истекла. Обновите страницу и войдите в аккаунт заново.');
            if (response.status === 422) throw new Error('Сервер отклонил подписку этого браузера. Сообщите администратору: ошибка подписки 422.');
            if (response.status === 503) throw new Error('Push-уведомления не настроены на сервере. Сообщите администратору.');
            if (!response.ok) throw new Error('Не удалось сохранить настройки уведомлений. Проверьте подключение и войдите в аккаунт заново.');
            if (method === 'DELETE') subscriptionSyncs.clear();
        },
        applicationKey() {
            const padded = this.publicKey.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - this.publicKey.length % 4) % 4);
            return Uint8Array.from(atob(padded), character => character.charCodeAt(0));
        },
        async toggle() {
            if (this.busy) return;
            this.message = '';
            if (this.needsInstallation) {
                this.message = 'На iPhone: откройте меню «Поделиться», выберите «На экран Домой», затем откройте чат через его иконку.';
                return;
            }
            if (!window.isSecureContext) { this.message = 'Для уведомлений откройте чат по HTTPS: на обычном HTTP телефон не разрешает push-уведомления.'; return; }
            if (!this.supported) { this.message = 'Этот браузер не поддерживает push-уведомления. Обновите Opera или откройте чат в Chrome. На iPhone требуется iOS 16.4 или новее.'; return; }
            if (!this.publicKey) { this.message = 'Уведомления пока не настроены на сервере.'; return; }
            this.busy = true;
            try {
                if (this.enabled) {
                    const subscription = await this.currentSubscription();
                    if (subscription) {
                        await this.save(subscription, 'DELETE');
                        if (!await withTimeout(subscription.unsubscribe(), 'Браузер не смог отключить подписку. Попробуйте снова.')) throw new Error('Не удалось отключить уведомления. Попробуйте снова.');
                    }
                    this.enabled = false;
                    this.checked = true;
                    window.dispatchEvent(new CustomEvent('device-push-changed', { detail: { enabled: false } }));
                    this.message = 'Уведомления на этом устройстве отключены.';
                    return;
                }
                // Request permission directly from the button click, before awaiting registration.
                const permission = Notification.permission === 'granted' ? 'granted' : await new Promise((resolve, reject) => {
                    // Some mobile browser versions use the callback API.
                    const request = Notification.requestPermission(resolve);
                    request?.then(resolve, reject);
                });
                if (permission !== 'granted') {
                    this.message = permission === 'denied'
                        ? this.errorMessage({ name: 'NotAllowedError' })
                        : 'Чтобы получать уведомления, разрешите их при следующем включении.';
                    return;
                }
                await this.prepareRegistration();
                let subscription = await this.currentSubscription();
                subscription ??= await withTimeout(this.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.applicationKey() }),
                    'Браузер не смог подключиться к службе push за 20 секунд. Проверьте соединение и попробуйте снова.');
                await this.save(subscription);
                this.enabled = true;
                this.checked = true;
                window.dispatchEvent(new CustomEvent('device-push-changed', { detail: { enabled: true } }));
                this.message = 'Уведомления включены на этом устройстве.';
            } catch (error) {
                this.message = this.errorMessage(error);
            } finally { this.busy = false; }
        },
    };
}

window.chatPushNotifications = chatPushNotifications;
