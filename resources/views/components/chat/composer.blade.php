@props([
    'chat' => [],
    'showMentionPicker' => false,
    'mentionCandidates' => [],
    'pendingFiles' => [],
])

<div
    class="shrink-0 border-t border-zinc-200 bg-white px-2 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-2 lg:px-3 lg:py-3 dark:border-zinc-700 dark:bg-zinc-800"
    x-data="{ sending: false, uploading: false, uploadProgress: 0 }"
    @mention-inserted.window="$nextTick(() => $refs.messageInput?.focus())"
    x-on:livewire-upload-start="uploading = true; uploadProgress = 0"
    x-on:livewire-upload-progress="uploadProgress = $event.detail.progress"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-error="uploading = false"
>
    <div class="mx-auto max-w-3xl">
        @if ($showMentionPicker)
            <div
                class="mb-2 max-h-56 overflow-y-auto rounded-xl border border-zinc-200 bg-white p-2 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                x-on:click.outside="if (!$event.target.closest('[data-test=mention-button]')) $wire.closeMentionPicker()"
                data-test="mention-picker"
            >
                <flux:input wire:model.live.debounce.200ms="mentionSearch" size="sm" icon="magnifying-glass" :placeholder="__('Найти участника чата')" :aria-label="__('Найти участника чата')" />
                <div class="mt-2 space-y-1">
                    @forelse ($mentionCandidates as $colleague)
                        <button type="button" wire:key="mention-{{ $colleague['id'] }}" wire:click="mentionColleague({{ $colleague['id'] }})" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                            <flux:avatar :name="$colleague['name']" color="auto" size="xs" />
                            <span class="truncate">{{ $colleague['name'] }}</span>
                            @if ($colleague['title'])
                                <span class="ms-auto truncate text-xs text-zinc-500">{{ $colleague['title'] }}</span>
                            @endif
                        </button>
                    @empty
                        <p class="px-2 py-2 text-xs text-zinc-500">{{ __('Участники не найдены') }}</p>
                    @endforelse
                </div>
            </div>
        @endif

        @if ($pendingFiles !== [])
            <div class="mb-2 flex flex-wrap gap-2" data-test="pending-files">
                @foreach ($pendingFiles as $index => $file)
                    <span class="flex max-w-full items-center gap-1 rounded-lg bg-zinc-100 px-2 py-1 text-xs dark:bg-zinc-700" wire:key="pending-file-{{ $index }}">
                        <flux:icon.paper-clip class="size-3.5 shrink-0" />
                        <span class="max-w-48 truncate">{{ $file->getClientOriginalName() }}</span>
                        <button type="button" wire:click="removePendingFile({{ $index }})" :aria-label="__('Убрать файл')" class="rounded p-0.5 hover:bg-zinc-200 dark:hover:bg-zinc-600"><flux:icon.x-mark class="size-3.5" /></button>
                    </span>
                @endforeach
            </div>
        @endif

        <div x-show="uploading" x-cloak style="display: none" class="mb-2 space-y-1" data-test="chat-file-upload-progress">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Загрузка файлов') }} · <span x-text="uploadProgress + '%'">0%</span></p>
            <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-label="{{ __('Загрузка файлов') }}" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="uploadProgress">
                <div class="h-full rounded-full bg-sky-500 transition-[width]" x-bind:style="'width: ' + uploadProgress + '%'"> </div>
            </div>
        </div>
        @error('pendingFiles') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('pendingFiles.*') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('messageBody') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror

        <div class="flex items-end gap-2">
            <div class="flex items-center gap-0.5">
                <input type="file" multiple class="sr-only" wire:model="pendingFiles" x-ref="chatFileInput" aria-label="{{ __('Выбрать файлы') }}" data-test="chat-file-input" />
                <flux:tooltip :content="__('Прикрепить файл')">
                    <flux:button size="sm" variant="ghost" icon="paper-clip" square x-on:click="$refs.chatFileInput.click()" data-test="attach-file-button" />
                </flux:tooltip>

                <flux:tooltip :content="__('Эмодзи')" class="hidden lg:block">
                    <flux:button size="sm" variant="ghost" icon="face-smile" square data-test="emoji-button" />
                </flux:tooltip>

                <flux:tooltip :content="__('Упомянуть коллегу')">
                    <flux:button size="sm" variant="ghost" icon="at-symbol" square wire:click="openMentionPicker" data-test="mention-button" />
                </flux:tooltip>
            </div>

            <flux:textarea
                wire:model.live.debounce.200ms="messageBody"
                x-ref="messageInput"
                rows="1"
                resize="none"
                class="min-w-0 flex-1"
                :placeholder="__('Написать сообщение…')"
                :aria-label="__('Новое сообщение')"
                data-test="message-input"
                @keydown.enter="if ($event.shiftKey || $event.isComposing) return; $event.preventDefault(); if (sending || uploading) return; sending = true; $wire.sendMessage($event.target.value).finally(() => sending = false)"
            />

            <flux:button
                variant="primary"
                icon="paper-airplane"
                square
                :aria-label="__('Отправить')"
                x-on:click.prevent="if (sending || uploading) return; sending = true; $wire.sendMessage($refs.messageInput.value).finally(() => sending = false)"
                x-bind:disabled="sending || uploading"
                data-test="send-message"
            />
        </div>

        <p class="mt-2 hidden px-1 text-[11px] text-zinc-400 lg:block">
            {{ __('Enter — отправить, Shift + Enter — новая строка') }}
            {{ __('· перетащите файлы сюда или нажмите на скрепку') }}
            {{ __('· до 3 файлов по 2 МБ') }}

            @if ($chat['type'] === 'group')
                {{ __('· сообщение увидят все участники чата') }}
            @endif
        </p>
    </div>
</div>
