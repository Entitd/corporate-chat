<section x-data="chatShareTarget()" x-show="visible" x-cloak class="shrink-0 border-b border-sky-200 bg-sky-50 px-4 py-3 text-sm dark:border-sky-800 dark:bg-zinc-900" data-test="share-target">
    <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-2">
        <div class="min-w-0 flex-1">
            <p class="font-semibold">{{ __('Отправить в чат') }}</p>
            <p x-show="loading">{{ __('Получаем переданные файлы…') }}</p>
            <p class="truncate" x-text="files.map(file => file.name).join(', ') || text"></p>
            <p x-show="!loading && !error && ($wire.activeChatId <= 0 || $wire.showChatList)" class="text-zinc-500">{{ __('Выберите чат в списке ниже.') }}</p>
            <p x-show="!loading && !error && $wire.activeChatId > 0 && !$wire.showChatList" class="text-zinc-500">{{ __('Прикрепите к открытому чату, затем нажмите «Отправить».') }}</p>
            <p x-show="error" x-text="error" role="alert" class="text-red-600 dark:text-red-400"></p>
        </div>
        <button type="button" x-show="!loading && (files.length || text) && $wire.activeChatId > 0 && !$wire.showChatList" x-bind:disabled="uploading" x-on:click="attach()" class="rounded-lg bg-sky-600 px-3 py-2 font-semibold text-white disabled:opacity-50" data-test="attach-shared-files">{{ __('Прикрепить') }}</button>
        <button type="button" x-bind:disabled="loading || uploading" x-on:click="dismiss()" class="rounded-lg px-3 py-2 hover:bg-zinc-200 dark:hover:bg-zinc-700">{{ __('Отмена') }}</button>
    </div>
    <div x-show="uploading" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="status" aria-live="polite">
        <p class="rounded-xl bg-white px-6 py-4 text-zinc-900 dark:bg-zinc-800 dark:text-white">{{ __('Прикрепляем файлы…') }} <span x-text="progress + '%'"></span></p>
    </div>
</section>
