@props([
    'chat' => [],
    'messages' => [],
    'showMessageSearch' => false,
])

<div
    class="min-h-0 flex-1 overflow-y-auto bg-[#e9f0eb] px-2 py-4 sm:px-6 lg:bg-zinc-50 dark:bg-[#101b26] dark:lg:bg-zinc-800/60"
    x-data="{ previousHeight: 0 }"
    x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
    @message-sent.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
    @message-search-updated.window="$nextTick(() => { $el.scrollTop = 0 })"
    @older-messages-loaded.window="$nextTick(() => { $el.scrollTop += $el.scrollHeight - previousHeight })"
    @focus-chat-message.window="$nextTick(() => document.getElementById('chat-message-' + $event.detail.id)?.scrollIntoView({ block: 'center' }))"
    x-on:touchmove.passive="if (document.activeElement?.matches('[data-test=message-input]')) document.activeElement.blur()"
    data-test="chat-thread"
>
    <div class="mx-auto flex max-w-3xl flex-col gap-2.5 lg:gap-4">
        @if (! $showMessageSearch && count($messages['data']) < $messages['total'])
            <button type="button" wire:click="loadOlderMessages" x-on:click="previousHeight = $el.closest('[data-test=chat-thread]').scrollHeight" class="mx-auto rounded-lg px-3 py-1.5 text-xs text-zinc-500 hover:bg-zinc-200/70 dark:hover:bg-zinc-700" data-test="load-older-messages">
                {{ __('Загрузить предыдущие сообщения') }}
            </button>
        @endif

        {{-- Начало переписки --}}
        <div class="flex items-center justify-center gap-3 py-2">
            <flux:separator class="flex-1" />
            <span class="rounded-full bg-zinc-700/40 px-3 py-1 text-[11px] font-medium text-white lg:bg-transparent lg:px-0 lg:py-0 lg:tracking-wide lg:text-zinc-400 lg:uppercase dark:bg-white/15 dark:lg:bg-transparent">{{ __('Начало переписки') }}</span>
            <flux:separator class="flex-1" />
        </div>

        @forelse ($messages['data'] as $messageIndex => $message)
        {{-- @dd($message) --}}
                <div
                    class="flex items-center justify-center py-2"
                    wire:key="day-{{ $chat['id'] }}-{{ $message['id'] }}"
                    data-chat-day
                    data-current="{{ $message['created_at'] }}"
                    data-previous="{{ $messages['data'][$messageIndex - 1]['created_at'] ?? '' }}"
                    x-show="!sameLocalDay($el.dataset.current, $el.dataset.previous)"
                    @unless ($message['show_day']) style="display: none" @endunless
                >
                    <time datetime="{{ $message['created_at'] }}" x-text="formatChatDate($el.dateTime)" class="rounded-full bg-zinc-700/40 px-3 py-1 text-[11px] font-medium text-white dark:bg-white/15">{{ \Illuminate\Support\Carbon::parse($message['created_at'])->format('d.m.Y') }}</time>
                </div>

            <x-chat.message
                id="chat-message-{{ $message['id'] }}"
                wire:key="message-{{ $chat['id'] }}-{{ $message['id'] }}"
                :message="$message"
                :message-index="$messageIndex"
                :is-group-chat="$chat['type'] === 'group'"
            />
        @empty
            <p class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {{ trim($this->messageSearch) !== '' ? __('Сообщения не найдены') : __('В этом чате пока нет сообщений') }}
            </p>
        @endforelse

        {{-- Индикатор набора текста --}}
        @if (filled($chat['typing'] ?? null))
            <div class="flex items-end gap-3">
                <flux:avatar :name="$chat['typing']" color="auto" size="sm" />

                <div class="flex items-center gap-1 rounded-2xl bg-zinc-100 px-3.5 py-3 dark:bg-zinc-700/60">
                    <span class="size-1.5 animate-bounce rounded-full bg-zinc-400"></span>
                    <span class="size-1.5 animate-bounce rounded-full bg-zinc-400 [animation-delay:150ms]"></span>
                    <span class="size-1.5 animate-bounce rounded-full bg-zinc-400 [animation-delay:300ms]"></span>
                    <span class="sr-only">{{ $chat['typing'] }} {{ __('печатает') }}</span>
                </div>
            </div>
        @endif
    </div>
</div>
