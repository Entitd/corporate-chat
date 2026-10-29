const cacheName = 'chat-share-target-v1';
const maxAge = 60 * 60 * 1000;

export function chatShareTarget() {
    return {
        visible: false,
        loading: false,
        uploading: false,
        progress: 0,
        files: [],
        text: '',
        error: '',
        id: null,
        async init() {
            const params = new URL(window.location.href).searchParams;
            const shareError = params.get('shareError');
            if (shareError) {
                this.visible = true;
                this.error = shareError === 'count' ? 'Можно прикрепить не больше 3 файлов.'
                    : shareError === 'empty' ? 'Приложение не передало файлы или текст.'
                    : 'Не удалось сохранить файлы на телефоне. Повторите действие «Поделиться».';
                return;
            }

            this.id = params.get('share');
            if (!this.id) return;
            this.visible = true;
            this.loading = true;
            try {
                if (!/^[a-f0-9-]{36}$/i.test(this.id)) throw new Error();
                const cache = await caches.open(cacheName);
                const saved = await cache.match(this.cacheKey());
                if (!saved || Number(saved.headers.get('X-Shared-At')) < Date.now() - maxAge) {
                    await cache.delete(this.cacheKey());
                    throw new Error();
                }
                const data = await saved.formData();
                this.files = data.getAll('files').filter(value => typeof value !== 'string');
                this.text = [...new Set(['text', 'url'].map(key => String(data.get(key) || '').trim()).filter(Boolean))].join('\n');
                if (!this.text && this.files.length === 0) this.text = String(data.get('title') || '').trim();
                this.validateFiles();
            } catch (error) {
                this.error = error.message || 'Переданные файлы недоступны или срок хранения истёк. Повторите действие «Поделиться».';
            } finally {
                this.loading = false;
            }
        },
        cacheKey() {
            return `${window.location.origin}/chat/share-data/${this.id}`;
        },
        validateFiles() {
            const limits = this.$el.closest('[data-max-file-size]').dataset;
            if (this.files.length + (this.$wire.pendingFiles?.length || 0) > 3) {
                throw new Error('Можно прикрепить не больше 3 файлов.');
            }
            if (this.files.some(file => file.size > Number(limits.maxFileSize))) throw new Error(limits.fileSizeError);
            if ([this.$wire.messageBody, this.text].filter(Boolean).join('\n').length > 5000) {
                throw new Error('Текст сообщения должен быть не длиннее 5000 символов.');
            }
        },
        async attach() {
            if (this.loading || this.uploading || this.$wire.activeChatId <= 0 || this.$wire.showChatList) return;
            this.error = '';
            try {
                this.validateFiles();
            } catch (error) {
                this.error = error.message;
                return;
            }
            this.uploading = true;
            try {
                if (this.files.length) {
                    await new Promise((resolve, reject) => {
                        this.$wire.uploadMultiple('pendingFiles', this.files, resolve,
                            () => reject(new Error('Не удалось загрузить файлы. Проверьте соединение и попробуйте снова.')),
                            event => { this.progress = event.detail.progress; },
                            () => reject(new Error('Загрузка отменена. Можно попробовать снова.')));
                    });
                }
                if (this.text) {
                    await this.$wire.$set('messageBody', [this.$wire.messageBody, this.text].filter(Boolean).join('\n'), false);
                }
                await this.dismiss();
            } catch (error) {
                this.error = error.message || 'Не удалось подготовить вложения. Попробуйте снова.';
            } finally {
                this.uploading = false;
            }
        },
        async dismiss() {
            // The draft is already attached even if local cache cleanup fails.
            try {
                if (this.id) await (await caches.open(cacheName)).delete(this.cacheKey());
            } catch {}
            const url = new URL(window.location.href);
            url.searchParams.delete('share');
            url.searchParams.delete('shareError');
            window.history.replaceState(window.history.state, '', url);
            this.files = [];
            this.text = '';
            this.visible = false;
        },
    };
}

window.chatShareTarget = chatShareTarget;

// Receiving shares works independently of push notification permission.
if (window.isSecureContext && 'serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' }).catch(() => {});
}
