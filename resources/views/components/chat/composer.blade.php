@props([
    'chat' => [],
])

<div class="shrink-0 border-t border-zinc-200 bg-white px-3 py-3 dark:border-zinc-700 dark:bg-zinc-800">
    <div class="mx-auto max-w-3xl">
        <div class="flex items-end gap-2">
            <div class="flex items-center gap-0.5">
                <flux:tooltip :content="__('Прикрепить файл')">
                    <flux:button size="sm" variant="ghost" icon="paper-clip" square data-test="attach-file-button" />
                </flux:tooltip>

                <flux:tooltip :content="__('Эмодзи')">
                    <flux:button size="sm" variant="ghost" icon="face-smile" square data-test="emoji-button" />
                </flux:tooltip>

                <flux:tooltip :content="__('Упомянуть коллегу')" class="hidden sm:block">
                    <flux:button size="sm" variant="ghost" icon="at-symbol" square data-test="mention-button" />
                </flux:tooltip>
            </div>

            <flux:textarea
                wire:model="messageBody"
                rows="1"
                resize="none"
                class="min-w-0 flex-1"
                :placeholder="__('Написать сообщение…')"
                :aria-label="__('Новое сообщение')"
                data-test="message-input"
                @keydown.enter.exact.prevent="$wire.sendMessage()"
            />

            <flux:button
                variant="primary"
                icon="paper-airplane"
                square
                :aria-label="__('Отправить')"
                wire:click="sendMessage"
                data-test="send-message"
            />
        </div>

        <p class="mt-2 px-1 text-[11px] text-zinc-400">
            {{ __('Enter — отправить, Shift + Enter — новая строка') }}

            @if ($chat['type'] === 'group')
                {{ __('· сообщение увидят все участники чата') }}
            @endif
        </p>
    </div>
</div>
