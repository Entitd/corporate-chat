@props([
    'chat' => [],
    'messages' => [],
    'showMessageSearch' => false,
    'lastReadMessageId' => null,
    'hasUnread' => false,
    'unreadDividerCount' => 0,
])

@php($firstUnreadMessage = collect($messages['data'])->first(fn (array $message): bool => ! $message['own'] && $message['id'] > ($lastReadMessageId ?? 0)))

<div
    wire:key="chat-thread-{{ $chat['id'] }}"
    class="min-h-0 flex-1 overflow-y-auto bg-[#e9f0eb] px-2 py-4 sm:px-6 lg:bg-zinc-50 dark:bg-[#101b26] dark:lg:bg-zinc-800/60"
    x-data="{
        previousHeight: 0,
        userNavigated: false,
        followingNewMessages: false,
        readingMessageId: null,
        lastMarkedMessageId: 0,
        isAtBottom() {
            return $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 24;
        },
        markMessageRead(messageId) {
            if (!messageId || this.readingMessageId !== null || messageId <= this.lastMarkedMessageId || messageId <= Number($el.dataset.lastReadMessageId)) return;

            this.readingMessageId = messageId;
            $wire.markOpenChatAsRead(messageId, false).then(() => {
                this.lastMarkedMessageId = messageId;
            }).finally(() => {
                if (this.readingMessageId === messageId) this.readingMessageId = null;
                if (this.lastMarkedMessageId === messageId) this.readVisibleMessages();
            });
        },
        readVisibleWithoutScrolling() {
            if ($el.dataset.hasUnread !== 'true' || $el.dataset.searching === 'true') return false;
            if ($el.scrollHeight > $el.clientHeight + 1 || document.visibilityState !== 'visible' || !document.hasFocus() || !$el.getClientRects().length) return false;

            const lastMessage = [...$el.querySelectorAll('[data-chat-message-id]')].at(-1);
            if (!lastMessage) return false;

            this.followingNewMessages = true;
            this.markMessageRead(Number(lastMessage.dataset.chatMessageId));
            return true;
        },
        readVisibleMessages() {
            if ($el.dataset.hasUnread !== 'true' || $el.dataset.searching === 'true') return;
            if (document.visibilityState !== 'visible' || !document.hasFocus() || !$el.getClientRects().length) return;

            const viewport = $el.getBoundingClientRect();
            const visibleMessages = [...$el.querySelectorAll('[data-chat-message-id]')].filter(message => {
                const bounds = message.getBoundingClientRect();
                return bounds.bottom > viewport.top && bounds.bottom <= viewport.bottom && bounds.top < viewport.bottom;
            });
            const lastMessage = visibleMessages.at(-1);
            if (lastMessage) this.markMessageRead(Number(lastMessage.dataset.chatMessageId));
        },
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
        handleScroll() {
            this.followingNewMessages = this.isAtBottom() && $el.dataset.searching !== 'true';
            if (!this.userNavigated) return;
            this.userNavigated = false;
            if (!this.followingNewMessages || $el.dataset.hasUnread !== 'true') return;

            this.readVisibleMessages();
        },
        handleIncomingMessage(event) {
            if (Number(event.detail.chatId) !== Number($el.dataset.chatId)) return;
            if ($el.dataset.searching === 'true' || !this.followingNewMessages || !$el.getClientRects().length) return;

            this.$nextTick(() => {
                $el.scrollTop = $el.scrollHeight;
                this.readVisibleMessages();
            });
        },
        handleVisibilityChange() {
            this.readVisibleMessages();
        },
    }"
    x-init="$nextTick(() => { scrollToOpeningPosition(); followingNewMessages = isAtBottom() && $el.dataset.searching !== 'true'; readVisibleWithoutScrolling() })"
    data-chat-id="{{ $chat['id'] }}"
    data-last-read-message-id="{{ $lastReadMessageId }}"
    data-has-unread="{{ $hasUnread ? 'true' : 'false' }}"
    data-searching="{{ $showMessageSearch ? 'true' : 'false' }}"
    x-on:wheel="userNavigated = true"
    x-on:touchmove.passive="userNavigated = true; if (document.activeElement?.matches('[data-test=message-input]')) document.activeElement.blur()"
    x-on:keydown="if (['ArrowDown', 'PageDown', 'End', ' '].includes($event.key)) userNavigated = true"
    x-on:scroll="handleScroll()"
    x-on:mousemove.throttle.200ms.window="readVisibleMessages()"
    x-on:resize.window="$nextTick(() => readVisibleWithoutScrolling())"
    @incoming-chat-message.window="handleIncomingMessage($event)"
    x-on:visibilitychange.document="handleVisibilityChange()"
    x-on:focus.window="readVisibleMessages()"
    @message-sent.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
    @chat-read-through.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight; followingNewMessages = true })"
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
            @if ($unreadDividerCount > 0 && ! $showMessageSearch && $firstUnreadMessage !== null && $message['id'] === $firstUnreadMessage['id'])
                <div class="flex items-center gap-3 py-2" data-test="unread-message-divider">
                    <span class="h-px flex-1 bg-sky-500/60"></span>
                    <span class="text-xs font-semibold text-sky-700 dark:text-sky-300">{{ $unreadDividerCount === 1 ? __('Новое сообщение') : __('Новые сообщения') }}</span>
                    <span class="h-px flex-1 bg-sky-500/60"></span>
                </div>
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
