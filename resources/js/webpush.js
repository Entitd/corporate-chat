export function chatPushNotifications() {
    return {
        busy: false,
        enabled: false,
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
            if (this.needsInstallation || !this.supported || !this.publicKey) return;
            this.busy = true;
            try {
                await navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' });
                this.registration = await navigator.serviceWorker.ready;
                const subscription = await this.registration.pushManager.getSubscription();
                if (subscription && Notification.permission === 'granted') {
                    await this.save(subscription);
                    this.enabled = true;
                }
            } catch {
                this.message = 'Не удалось подготовить уведомления. Обновите страницу и попробуйте снова.';
            } finally { this.busy = false; }
        },
        async save(subscription, method = 'POST') {
            const response = await fetch(this.endpoint, {
                method,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(subscription.toJSON()),
            });
            if (!response.ok) throw new Error('Не удалось сохранить настройки уведомлений. Проверьте подключение и войдите в аккаунт заново.');
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
            if (!this.supported) { this.message = 'Для уведомлений нужен поддерживаемый браузер и HTTPS. На iPhone требуется iOS 16.4 или новее.'; return; }
            if (!this.publicKey) { this.message = 'Уведомления пока не настроены на сервере.'; return; }
            this.busy = true;
            try {
                if (this.enabled) {
                    const subscription = await this.registration.pushManager.getSubscription();
                    if (subscription) {
                        await this.save(subscription, 'DELETE');
                        if (!await subscription.unsubscribe()) throw new Error('Не удалось отключить уведомления. Попробуйте снова.');
                    }
                    this.enabled = false;
                    this.message = 'Уведомления на этом устройстве отключены.';
                    return;
                }
                // Request permission directly from the button click, before awaiting registration.
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    this.message = permission === 'denied'
                        ? 'Уведомления запрещены. Разрешите их в настройках уведомлений приложения или браузера.'
                        : 'Чтобы получать уведомления, разрешите их при следующем включении.';
                    return;
                }
                await navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' });
                this.registration = await navigator.serviceWorker.ready;
                let subscription = await this.registration.pushManager.getSubscription();
                subscription ??= await this.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.applicationKey() });
                await this.save(subscription);
                this.enabled = true;
                this.message = 'Уведомления включены на этом устройстве.';
            } catch (error) {
                this.message = error.message || 'Не удалось включить уведомления. Попробуйте снова.';
            } finally { this.busy = false; }
        },
    };
}

window.chatPushNotifications = chatPushNotifications;
