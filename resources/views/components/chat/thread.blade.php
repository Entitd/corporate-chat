@props([
    'chat' => [],
    'messages' => [],
    'showMessageSearch' => false,
    'lastReadMessageId' => null,
    'hasUnread' => false,
])

<div
    wire:key="chat-thread-{{ $chat['id'] }}"
    class="min-h-0 flex-1 overflow-y-auto bg-[#e9f0eb] px-2 py-4 sm:px-6 lg:bg-zinc-50 dark:bg-[#101b26] dark:lg:bg-zinc-800/60"
    x-data="{
        previousHeight: 0,
        userNavigated: false,
        scrollToOpeningPosition() {
            if ($el.dataset.hasUnread !== 'true' || $el.dataset.searching === 'true') {
                $el.scrollTop = $el.scrollHeight;
                return;
            }

            const readMessageId = Number($el.dataset.lastReadMessageId);
            const readMessages = [...$el.querySelectorAll('[data-chat-message-id]')]
                .filter(message => Number(message.dataset.chatMessageId) <= readMessageId);
            const lastReadMessage = readMessages.at(-1);
            $el.scrollTop = lastReadMessage
                ? Math.max(0, $el.scrollTop + lastReadMessage.getBoundingClientRect().bottom - $el.getBoundingClientRect().bottom + 16)
                : 0;
        },
        markReadAtBottom() {
            if (!this.userNavigated || $el.dataset.hasUnread !== 'true' || $el.dataset.searching === 'true') return;
            if ($el.scrollTop + $el.clientHeight < $el.scrollHeight - 24) return;

            this.userNavigated = false;
            const lastMessage = [...$el.querySelectorAll('[data-chat-message-id]')].at(-1);
            if (lastMessage) $wire.markOpenChatAsRead(Number(lastMessage.dataset.chatMessageId));
        },
    }"
    x-init="$nextTick(() => scrollToOpeningPosition())"
    data-last-read-message-id="{{ $lastReadMessageId }}"
    data-has-unread="{{ $hasUnread ? 'true' : 'false' }}"
    data-searching="{{ $showMessageSearch ? 'true' : 'false' }}"
    x-on:wheel="userNavigated = $el.dataset.hasUnread === 'true'"
    x-on:touchmove.passive="userNavigated = $el.dataset.hasUnread === 'true'; if (document.activeElement?.matches('[data-test=message-input]')) document.activeElement.blur()"
    x-on:keydown="if (['ArrowDown', 'PageDown', 'End', ' '].includes($event.key)) userNavigated = $el.dataset.hasUnread === 'true'"
    x-on:scroll.debounce.150ms="markReadAtBottom()"
    @incoming-chat-message.window="userNavigated = false"
    @message-sent.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
    @chat-read-through.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
    @message-search-updated.window="$nextTick(() => { $el.scrollTop = 0 })"
    @older-messages-loaded.window="$nextTick(() => { $el.scrollTop += $el.scrollHeight - previousHeight })"
    @focus-chat-message.window="$nextTick(() => document.getElementById('chat-message-' + $event.detail.id)?.scrollIntoView({ block: 'center' }))"
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
            @if ($hasUnread && ! $showMessageSearch && $message['id'] > ($lastReadMessageId ?? 0) && ($messageIndex === 0 || $messages['data'][$messageIndex - 1]['id'] <= ($lastReadMessageId ?? 0)))
                <button type="button" wire:click="markOpenChatAsRead({{ $messages['data'][array_key_last($messages['data'])]['id'] }})" class="mx-auto rounded-full bg-sky-100 px-3 py-1.5 text-xs font-medium text-sky-800 dark:bg-sky-900 dark:text-sky-100" data-test="unread-messages-button">
                    {{ __('Непрочитанные сообщения · Показать новые') }}
                </button>
            @endif
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
                data-chat-message-id="{{ $message['id'] }}"
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
