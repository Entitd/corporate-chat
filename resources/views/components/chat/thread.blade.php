@props([
    'chat' => [],
    'messages' => [],
])

<div
    class="min-h-0 flex-1 overflow-y-auto bg-zinc-50 px-3 py-4 sm:px-6 dark:bg-zinc-800/60"
    x-data
    x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
    @message-sent.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
    @message-search-updated.window="$nextTick(() => { $el.scrollTop = 0 })"
    data-test="chat-thread"
>
    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        {{-- Начало переписки --}}
        <div class="flex items-center gap-3 py-2">
            <flux:separator class="flex-1" />
            <span class="text-[11px] font-medium tracking-wide text-zinc-400 uppercase">{{ __('Начало переписки') }}</span>
            <flux:separator class="flex-1" />
        </div>

        @forelse ($messages['data'] as $messageIndex => $message)
        {{-- @dd($message) --}}
            @if ($message['show_day'])
                <div class="flex items-center gap-3 py-2" wire:key="day-{{ $chat['id'] }}-{{ $message['id'] }}">
                    <flux:separator class="flex-1" />
                    {{-- <span class="text-[11px] font-medium tracking-wide text-zinc-400 uppercase">{{ $message['day'] }}</span> --}}
                    <flux:separator class="flex-1" />
                </div>
            @endif

            <x-chat.message
                wire:key="message-{{ $chat['id'] }}-{{ $message['id'] }}"
                :message="$message"
                :message-index="$messageIndex"
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
