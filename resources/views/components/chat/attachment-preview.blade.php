<div wire:ignore x-data="attachmentPreview()" x-on:open-attachment-preview.window="showPreview($event.detail)">
    <dialog x-ref="dialog" x-on:close="if (!$refs.dialog.open) reset()" x-on:click="if ($event.target === $refs.dialog) closePreview()"
        class="fixed inset-0 m-auto h-[92dvh] max-h-[92dvh] w-[96vw] max-w-5xl rounded-2xl border border-zinc-200 bg-white p-0 text-zinc-900 shadow-xl backdrop:bg-black/70 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
        aria-labelledby="attachment-preview-title" data-test="attachment-preview-dialog">
        <div class="flex h-full min-h-0 flex-col">
            <header class="flex shrink-0 items-center gap-2 border-b border-zinc-200 p-3 dark:border-zinc-700">
                <h2 id="attachment-preview-title" class="min-w-0 flex-1 truncate text-sm font-semibold" x-text="name"></h2>
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
            <div x-ref="pages" class="min-h-0 flex-1 overflow-auto overscroll-contain bg-zinc-100 p-4 dark:bg-zinc-950" aria-live="polite">
                <p x-show="loading" class="py-8 text-center text-sm text-zinc-500">{{ __('Загрузка файла…') }}</p>
                <p x-show="error" x-text="error" role="alert" class="py-8 text-center text-sm text-red-600 dark:text-red-400"></p>
                <img x-show="kind === 'image' && imageUrl && !error" x-bind:src="imageUrl || null" x-bind:alt="name" x-on:error="if (imageUrl) error = 'Не удалось показать изображение. Попробуйте скачать файл.'" class="m-auto max-h-full max-w-full rounded-lg object-contain" />
                <canvas x-ref="canvas" x-show="kind === 'pdf' && pages > 0 && !error" class="mx-auto block bg-white shadow" role="img" x-bind:aria-label="'Страница ' + page + ' из ' + pages + ': ' + name"></canvas>
            </div>
        </div>
    </dialog>
</div>
