export function attachmentPreview() {
    let controller = null;
    let loadingTask = null;
    let pdf = null;
    let renderTask = null;
    let revision = 0;

    return {
        name: '', download: '', kind: '', imageUrl: '', error: '', loading: false,
        page: 1, pages: 0, zoom: 1, rendering: false,

        async showPreview(file) {
            this.reset();
            const current = revision;
            this.name = file.name;
            this.download = file.download;
            this.loading = true;
            if (!this.$refs.dialog.open) this.$refs.dialog.showModal();
            controller = new AbortController();

            try {
                const response = await fetch(file.url, { credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/pdf, image/*' } });
                if (!response.ok) throw new Error([401, 403, 404].includes(response.status)
                    ? 'Файл недоступен. Проверьте доступ к чату и обновите страницу.'
                    : 'Не удалось открыть файл. Его можно скачать по кнопке выше.');
                const type = response.headers.get('Content-Type')?.split(';')[0];
                const data = await response.arrayBuffer();
                if (current !== revision) return;

                if (type?.startsWith('image/')) {
                    this.kind = 'image';
                    this.imageUrl = URL.createObjectURL(new Blob([data], { type }));
                } else if (type === 'application/pdf') {
                    this.kind = 'pdf';
                    const { loadPdf } = await import('./pdf-preview');
                    if (current !== revision) return;
                    loadingTask = loadPdf(data);
                    // Password protected PDFs remain downloadable, without a stalled dialog.
                    loadingTask.onPassword = () => {
                        if (current !== revision) return;
                        this.error = 'PDF защищён паролем. Скачайте его и откройте в приложении для PDF.';
                        this.loading = false;
                        loadingTask.destroy().catch(() => {});
                    };
                    const document = await loadingTask.promise;
                    if (current !== revision) return;
                    pdf = document;
                    this.pages = pdf.numPages;
                    await this.$nextTick();
                    await this.renderPage();
                } else {
                    throw new Error('Этот формат доступен только для скачивания.');
                }
            } catch (error) {
                if (current === revision && error.name !== 'AbortError') {
                    this.error ||= error.message || 'Не удалось открыть файл.';
                }
            } finally {
                if (current === revision) this.loading = false;
            }
        },

        async renderPage() {
            if (!pdf || this.rendering) return;
            const current = revision;
            this.rendering = true;
            try {
                const page = await pdf.getPage(this.page);
                if (current !== revision) return;
                const base = page.getViewport({ scale: 1 });
                const width = Math.max(200, this.$refs.pages.clientWidth - 32);
                const viewport = page.getViewport({ scale: Math.min(width / base.width, 1.6) * this.zoom });
                const canvas = this.$refs.canvas;
                const density = Math.min(window.devicePixelRatio || 1, 2);
                canvas.width = Math.ceil(viewport.width * density);
                canvas.height = Math.ceil(viewport.height * density);
                canvas.style.width = `${viewport.width}px`;
                canvas.style.height = `${viewport.height}px`;
                renderTask = page.render({ canvasContext: canvas.getContext('2d'), viewport, transform: [density, 0, 0, density, 0, 0] });
                await renderTask.promise;
            } catch (error) {
                if (current === revision && error.name !== 'RenderingCancelledException') this.error = 'Не удалось показать страницу PDF. Попробуйте скачать файл.';
            } finally {
                if (current === revision) { renderTask = null; this.rendering = false; }
            }
        },

        async changePage(delta) {
            if (this.rendering || this.page + delta < 1 || this.page + delta > this.pages) return;
            this.page += delta;
            this.$refs.pages.scrollTop = 0;
            await this.renderPage();
        },

        async changeZoom(delta) {
            if (this.rendering) return;
            this.zoom = Math.max(0.5, Math.min(3, this.zoom + delta));
            await this.renderPage();
        },

        closePreview() { this.reset(); this.$refs.dialog.close(); },
        reset() {
            revision++;
            controller?.abort();
            controller = null;
            renderTask?.cancel();
            renderTask = null;
            loadingTask?.destroy().catch(() => {});
            loadingTask = null;
            pdf = null;
            if (this.imageUrl) URL.revokeObjectURL(this.imageUrl);
            this.imageUrl = ''; this.kind = ''; this.error = ''; this.loading = false;
            this.page = 1; this.pages = 0; this.zoom = 1; this.rendering = false;
            if (this.$refs.canvas) { this.$refs.canvas.width = 0; this.$refs.canvas.height = 0; }
        },
        destroy() { this.reset(); },
    };
}

window.attachmentPreview = attachmentPreview;
