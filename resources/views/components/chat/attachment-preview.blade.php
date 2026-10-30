<div wire:ignore x-data="attachmentPreview()" x-on:open-attachment-preview.window="showPreview($event.detail)" x-on:resize.window.debounce.150ms="fitImage()" x-on:keydown.window="onKey($event)">
    <dialog x-ref="dialog" x-bind:class="{ 'photo-viewer': kind === 'image' }" x-on:close="if (!$refs.dialog.open) reset()" x-on:click="if ($event.target === $refs.dialog) closePreview()"
        class="fixed inset-0 m-auto h-[92dvh] max-h-[92dvh] w-[96vw] max-w-5xl rounded-2xl border border-zinc-200 bg-white p-0 text-zinc-900 shadow-xl backdrop:bg-black/70 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
        aria-labelledby="attachment-preview-title" data-test="attachment-preview-dialog">
        <div class="flex h-full min-h-0 flex-col">
            <header class="attachment-viewer-header flex shrink-0 items-center gap-2 border-b border-zinc-200 p-3 dark:border-zinc-700">
                <h2 id="attachment-preview-title" class="min-w-0 flex-1 truncate text-sm font-semibold" x-text="name"></h2>
                <span x-show="kind === 'image' && photoIndex >= 0" class="shrink-0 text-xs opacity-60" x-text="(photoIndex + 1) + ' / ' + gallery.length"></span>
                <a x-bind:href="download" class="rounded-lg p-2 hover:bg-zinc-100 dark:hover:bg-zinc-800" title="{{ __('Скачать') }}" aria-label="{{ __('Скачать файл') }}"><flux:icon.arrow-down-tray class="size-5" /></a>
                <button type="button" x-on:click="closePreview()" class="rounded-lg p-2 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="{{ __('Закрыть просмотр') }}" autofocus><flux:icon.x-mark class="size-5" /></button>
            </header>
            <div x-show="kind === 'pdf' && pages > 0 && !error" class="flex shrink-0 flex-wrap items-center justify-center gap-2 border-b border-zinc-200 p-2 text-xs dark:border-zinc-700">
                <button type="button" x-on:click="changePage(-1)" x-bind:disabled="page <= 1 || rendering" class="rounded-lg p-2 disabled:opacity-30" aria-label="{{ __('Предыдущая страница') }}"><flux:icon.chevron-left class="size-4" /></button>
                <span><span x-text="page"></span> / <span x-text="pages"></span></span>
                <button type="button" x-on:click="changePage(1)" x-bind:disabled="page >= pages || rendering" class="rounded-lg p-2 disabled:opacity-30" aria-label="{{ __('Следующая страница') }}"><flux:icon.chevron-right class="size-4" /></button>
                <span class="mx-1 h-4 border-s border-zinc-300 dark:border-zinc-600"></span>
                <button type="button" x-on:click="changeZoom(-0.25)" x-bind:disabled="zoom <= 0.5 || rendering" class="rounded-lg p-2 disabled:opacity-30" aria-label="{{ __('Уменьшить') }}"><flux:icon.minus class="size-4" /></button>
                <span x-text="Math.round(zoom * 100) + '%'" class="min-w-10 text-center"></span>
                <button type="button" x-on:click="changeZoom(0.25)" x-bind:disabled="zoom >= 3 || rendering" class="rounded-lg p-2 disabled:opacity-30" aria-label="{{ __('Увеличить') }}"><flux:icon.plus class="size-4" /></button>
            </div>
            <div x-show="kind === 'image' && imageUrl && !error" class="photo-viewer-controls flex shrink-0 items-center justify-center gap-2 p-2 text-xs" data-test="image-preview-controls">
                <button type="button" x-on:click="changeZoom(-0.25)" x-bind:disabled="zoom <= 1" class="rounded-lg p-2 disabled:opacity-30" aria-label="{{ __('Уменьшить фото') }}"><flux:icon.minus class="size-4" /></button>
                <span x-text="Math.round(zoom * 100) + '%'" class="min-w-10 text-center"></span>
                <button type="button" x-on:click="changeZoom(0.25)" x-bind:disabled="zoom >= 5" class="rounded-lg p-2 disabled:opacity-30" aria-label="{{ __('Увеличить фото') }}"><flux:icon.plus class="size-4" /></button>
                <button type="button" x-on:click="setImageZoom(1)" class="rounded-lg px-3 py-2 hover:bg-zinc-100 dark:hover:bg-zinc-800">{{ __('По размеру окна') }}</button>
            </div>
            <div x-ref="stage" x-show="kind === 'image'" class="photo-viewer-stage relative min-h-0 flex-1 overflow-hidden select-none"
                x-on:wheel.prevent="wheelZoom($event)"
                x-on:pointerdown="pointerDown($event)" x-on:pointermove="pointerMove($event)"
                x-on:pointerup="pointerUp($event)" x-on:pointercancel="pointerUp($event)"
                x-on:dblclick.prevent="toggleImageZoom()"
                >
                <p x-show="loading" class="absolute inset-x-0 top-1/2 text-center text-sm text-white/60">{{ __('Загрузка фото…') }}</p>
                <p x-show="error" x-text="error" role="alert" class="absolute inset-x-0 top-1/2 px-6 text-center text-sm text-red-300"></p>
                <img x-ref="image" x-on:load="fitImage()" draggable="false"
                    x-show="imageUrl && !error" x-bind:src="imageUrl || null" x-bind:alt="name"
                    x-on:error="if (imageUrl) error = 'Не удалось показать изображение. Попробуйте скачать файл.'"
                    x-bind:style="{ width: imageWidth + 'px', height: imageHeight + 'px', transform: 'translate(-50%, -50%) translate(' + panX + 'px, ' + panY + 'px) scale(' + zoom + ')' }"
                    x-bind:class="zoom > 1 ? (dragging ? 'cursor-grabbing' : 'cursor-grab') : 'cursor-zoom-in'"
                    class="absolute start-1/2 top-1/2 max-w-none object-contain" />
            </div>
            <button type="button" x-show="kind === 'image' && photoIndex > 0" x-on:click="navigatePhoto(-1)" class="photo-viewer-arrow photo-viewer-previous" aria-label="{{ __('Предыдущее фото') }}"><flux:icon.chevron-left class="size-6" /></button>
            <button type="button" x-show="kind === 'image' && photoIndex >= 0 && photoIndex < gallery.length - 1" x-on:click="navigatePhoto(1)" class="photo-viewer-arrow photo-viewer-next" aria-label="{{ __('Следующее фото') }}"><flux:icon.chevron-right class="size-6" /></button>
            <div x-ref="pages" x-show="kind !== 'image'" class="min-h-0 flex-1 overflow-auto overscroll-contain bg-zinc-100 p-4 dark:bg-zinc-950" aria-live="polite">
                <p x-show="loading" class="py-8 text-center text-sm text-zinc-500">{{ __('Загрузка файла…') }}</p>
                <p x-show="error" x-text="error" role="alert" class="py-8 text-center text-sm text-red-600 dark:text-red-400"></p>

                <canvas x-ref="canvas" x-show="kind === 'pdf' && pages > 0 && !error" class="mx-auto block bg-white shadow" role="img" x-bind:aria-label="'Страница ' + page + ' из ' + pages + ': ' + name"></canvas>
            </div>
        </div>
    </dialog>
</div>
