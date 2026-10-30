export function attachmentPreview() {
    let controller = null;
    let loadingTask = null;
    let pdf = null;
    let renderTask = null;
    let revision = 0;
    const pointers = new Map();
    let gesture = null;
    let lastTap = 0;

    return {
        gallery: [], photoIndex: -1, panX: 0, panY: 0, dragging: false,
        name: '', download: '', kind: '', imageUrl: '', error: '', loading: false,
        page: 1, pages: 0, zoom: 1, rendering: false, imageWidth: 0, imageHeight: 0,

        async showPreview(file, navigating = false) {
            if (!navigating) {
                const photos = [...document.querySelectorAll('[data-chat-photo]')]
                    .filter(node => node.getClientRects().length)
                    .map(node => JSON.parse(node.dataset.chatPhoto));
                this.gallery = photos.filter((photo, index) => photos.findIndex(other => other.url === photo.url) === index);
                this.photoIndex = this.gallery.findIndex(photo => photo.url === file.url);
            }
            this.reset();
            this.kind = file.image ? 'image' : '';
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

        fitImage() {
            const image = this.$refs.image;
            if (this.kind !== 'image' || !image?.naturalWidth) return;
            const area = this.$refs.stage;
            const ratio = Math.min((area.clientWidth - 32) / image.naturalWidth,
                (area.clientHeight - 32) / image.naturalHeight, 1);
            this.imageWidth = Math.max(1, image.naturalWidth * ratio);
            this.imageHeight = Math.max(1, image.naturalHeight * ratio);
            this.clampPan();
        },

        toggleImageZoom() {
            this.setImageZoom(this.zoom === 1 ? 2 : 1);
        },

        async changeZoom(delta) {
            if (this.rendering) return;
            if (this.kind === 'image') { this.setImageZoom(this.zoom + delta); return; }
            this.zoom = Math.max(0.5, Math.min(this.kind === 'image' ? 5 : 3, this.zoom + delta));
            if (this.kind === 'pdf') await this.renderPage();
        },

        navigatePhoto(delta) {
            const index = this.photoIndex + delta;
            if (index < 0 || index >= this.gallery.length) return;
            this.photoIndex = index;
            this.showPreview(this.gallery[index], true);
        },

        onKey(event) {
            if (!this.$refs.dialog.open || this.kind !== 'image') return;
            if (event.key === 'ArrowLeft') { event.preventDefault(); this.navigatePhoto(-1); }
            if (event.key === 'ArrowRight') { event.preventDefault(); this.navigatePhoto(1); }
            if (['+', '='].includes(event.key)) { event.preventDefault(); this.changeZoom(0.25); }
            if (event.key === '-') { event.preventDefault(); this.changeZoom(-0.25); }
        },

        clampPan() {
            const area = this.$refs.stage;
            if (!area) return;
            const x = Math.max(0, (this.imageWidth * this.zoom - area.clientWidth) / 2);
            const y = Math.max(0, (this.imageHeight * this.zoom - area.clientHeight) / 2);
            this.panX = Math.max(-x, Math.min(x, this.panX));
            this.panY = Math.max(-y, Math.min(y, this.panY));
        },

        setImageZoom(value, clientX, clientY) {
            const previous = this.zoom;
            this.zoom = Math.max(1, Math.min(5, value));
            if (clientX !== undefined) {
                const rect = this.$refs.stage.getBoundingClientRect();
                const x = clientX - rect.left - rect.width / 2;
                const y = clientY - rect.top - rect.height / 2;
                this.panX = x - (x - this.panX) * this.zoom / previous;
                this.panY = y - (y - this.panY) * this.zoom / previous;
            }
            this.clampPan();
        },

        wheelZoom(event) {
            if (!this.imageUrl) return;
            this.setImageZoom(this.zoom * Math.exp(-event.deltaY * 0.002), event.clientX, event.clientY);
        },

        pointerDown(event) {
            if (!this.imageUrl || (event.pointerType === 'mouse' && event.button !== 0)) return;
            event.currentTarget.setPointerCapture(event.pointerId);
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            this.dragging = true;
            gesture = { x: event.clientX, y: event.clientY, moved: false };
        },

        pointerMove(event) {
            const previous = pointers.get(event.pointerId);
            if (!previous) return;
            const before = [...pointers.values()];
            pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            if (Math.hypot(event.clientX - gesture.x, event.clientY - gesture.y) > 8) gesture.moved = true;
            if (pointers.size === 2) {
                gesture.moved = true;
                const after = [...pointers.values()];
                const distance = points => Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y);
                const initial = distance(before);
                if (initial > 0) this.setImageZoom(this.zoom * distance(after) / initial,
                    (after[0].x + after[1].x) / 2, (after[0].y + after[1].y) / 2);
            } else if (this.zoom > 1) {
                this.panX += event.clientX - previous.x;
                this.panY += event.clientY - previous.y;
                this.clampPan();
            }
        },

        pointerUp(event) {
            if (!pointers.has(event.pointerId)) return;
            pointers.delete(event.pointerId);
            if (!pointers.size && gesture && event.type !== 'pointercancel') {
                const dx = event.clientX - gesture.x;
                const dy = event.clientY - gesture.y;
                if (this.zoom === 1 && Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                    this.navigatePhoto(dx < 0 ? 1 : -1);
                } else if (!gesture.moved && event.pointerType === 'touch') {
                    const now = Date.now();
                    if (now - lastTap < 300) { this.toggleImageZoom(); lastTap = 0; }
                    else lastTap = now;
                }
            }
            this.dragging = pointers.size > 0;
        },

        closePreview() { this.reset(); this.$refs.dialog.close(); },
        reset() {
            revision++;
            pointers.clear(); gesture = null; lastTap = 0;
            this.panX = 0; this.panY = 0; this.dragging = false;
            controller?.abort();
            controller = null;
            renderTask?.cancel();
            renderTask = null;
            loadingTask?.destroy().catch(() => {});
            loadingTask = null;
            pdf = null;
            if (this.imageUrl) URL.revokeObjectURL(this.imageUrl);
            this.imageWidth = 0; this.imageHeight = 0;
            this.imageUrl = ''; this.kind = ''; this.error = ''; this.loading = false;
            this.page = 1; this.pages = 0; this.zoom = 1; this.rendering = false;
            if (this.$refs.canvas) { this.$refs.canvas.width = 0; this.$refs.canvas.height = 0; }
        },
        destroy() { this.reset(); },
    };
}

window.attachmentPreview = attachmentPreview;
