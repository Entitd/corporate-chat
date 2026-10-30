@props([
    'message' => [],
    'messageIndex' => 0,
    'isGroupChat' => false,
])

@php
    $own = $message['own'];
    // dd($message);
    $author = $own ? auth()->user()->name : $message['user']['name'];
@endphp

<div x-data="messageContextMenu()" {{ $attributes->class(['flex items-end gap-1.5 lg:gap-2.5', 'flex-row-reverse' => $own]) }}>
    {{-- Аватар автора --}}
    @unless ($own)
        @if ($message['first_of_group'])
            <flux:avatar class="mb-5 hidden shrink-0 lg:inline-flex" :name="$author" color="auto" size="sm" />
        @else
            <span class="hidden w-8 shrink-0 lg:block" aria-hidden="true"></span>
        @endif
    @endunless

    <div @class([
        'relative flex min-w-0 max-w-[calc(100%-2.75rem)] flex-col gap-1 lg:max-w-[min(38rem,85%)]',
        'items-end' => $own,
        'items-start' => ! $own,
    ])>
        {{-- Имя автора --}}
        @if ($message['first_of_group'] && ! $own)
            <span class="px-1 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $author }}</span>
        @endif

        {{-- Ответ на сообщение --}}
        @if (filled($message['reply'] ?? null))
            <div class="flex max-w-full items-center gap-2 rounded-lg border-s-2 border-zinc-300 bg-zinc-100/80 px-2.5 py-1.5 dark:border-zinc-500 dark:bg-zinc-700/40">
                <flux:icon.arrow-uturn-left class="size-3.5 shrink-0 text-zinc-400" />
                <span class="min-w-0">
                    <span class="block text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ $message['reply']['author'] }}</span>
                    <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $message['reply']['body'] }}</span>
                </span>
            </div>
        @endif

        {{-- Пузырь сообщения --}}
        <div
            x-on:contextmenu="openMessageMenu($event)"
            x-on:pointerdown="startMessagePress($event)"
            x-on:pointermove="moveMessagePress($event)"
            x-on:pointerup="cancelMessagePress()"
            x-on:pointercancel="cancelMessagePress()"
            x-on:pointerleave="cancelMessagePress()"
            x-on:click.capture="messageContextClick($event)"
            @class([
            'chat-bubble w-fit max-w-full rounded-2xl px-3.5 py-2.5 text-[15px] leading-relaxed shadow-xs lg:text-sm',
            'rounded-br-md bg-sky-100 text-zinc-900 dark:bg-sky-950 dark:text-sky-50' => $own,
            'rounded-bl-md bg-white text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100' => ! $own,
        ])>
            @if ($message['forwarded_message_id'] ?? null)
                <p class="mb-1 text-xs font-medium opacity-70" data-test="forwarded-message">{{ __('Переслано от') }} {{ $message['forwarded_message']['user']['name'] ?? __('Пользователь') }}</p>
            @endif
            @if (filled($message['body']))
                <p class="break-words whitespace-pre-line [overflow-wrap:anywhere]">@foreach ($message['body_segments'] as $segment)@if ($segment['user_id'])<a href="{{ route('chat.members.show', ['chat' => $message['chat_id'], 'user' => $segment['user_id']]) }}" wire:click.prevent="showMentionProfile({{ $segment['user_id'] }})" class="rounded bg-sky-500/10 px-0.5 font-semibold text-sky-700 underline underline-offset-2 hover:opacity-75 dark:text-sky-300" data-test="message-mention-link">{{ $segment['text'] }}</a>@else{{ $segment['text'] }}@endif@endforeach</p>
            @endif

            {{-- Вложения --}}
            @foreach ($message['attachments'] ?? [] as $attachment)
                <x-chat.attachment :attachment="$attachment" />
            @endforeach

            {{-- Ссылка --}}
            @if (filled($message['link'] ?? null))
                <a
                    href="{{ $message['link']['url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-2 block rounded-xl bg-white/70 p-2.5 text-start dark:bg-black/20"
                >
                    <span class="block text-[11px] tracking-wide uppercase opacity-60">{{ $message['link']['host'] }}</span>
                    <span class="mt-0.5 block text-xs font-semibold">{{ $message['link']['title'] }}</span>
                    <span class="mt-1 block text-[11px] opacity-70">{{ $message['link']['description'] }}</span>
                </a>
            @endif

            {{-- Время и статус --}}
            <span class="mt-1 flex items-center justify-end gap-1.5 text-[10px]">
                <time class="opacity-70" datetime="{{ $message['created_at'] }}" x-text="formatChatTime($el.dateTime)">{{ \Illuminate\Support\Carbon::parse($message['created_at'])->format('H:i') }}</time>

                @if ($own)
                    @if ($isGroupChat)
                        <button
                            type="button"
                            wire:click="showMessageReaders({{ $message['id'] }})"
                            @class([
                                'flex items-center rounded p-1 -m-1 hover:bg-black/5 dark:hover:bg-white/10',
                                '-space-x-1.5 text-green-600 dark:text-green-400' => $message['status'] === 'read',
                                'text-zinc-500 dark:text-zinc-400' => $message['status'] === 'sent',
                            ])
                            title="{{ $message['status'] === 'read' ? __('Прочитано. Кто прочитал сообщение') : __('Отправлено. Кто прочитал сообщение') }}"
                            aria-label="{{ $message['status'] === 'read' ? __('Прочитано. Кто прочитал сообщение') : __('Отправлено. Кто прочитал сообщение') }}"
                            data-message-status="{{ $message['status'] }}"
                            data-test="show-message-readers"
                        >
                            <flux:icon.check class="size-3" />
                            @if ($message['status'] === 'read')
                                <flux:icon.check class="size-3" />
                            @endif
                        </button>
                    @else
                        <span
                            @class([
                                'flex items-center',
                                '-space-x-1.5 text-green-600 dark:text-green-400' => $message['status'] === 'read',
                                'text-zinc-500 dark:text-zinc-400' => $message['status'] === 'sent',
                            ])
                            title="{{ $message['status'] === 'read' ? __('Прочитано') : __('Отправлено') }}"
                            data-message-status="{{ $message['status'] }}"
                        >
                            <flux:icon.check class="size-3" />
                            @if ($message['status'] === 'read')
                                <flux:icon.check class="size-3" />
                            @endif
                            <span class="sr-only">{{ $message['status'] === 'read' ? __('Прочитано') : __('Отправлено') }}</span>
                        </span>
                    @endif
                @endif
            </span>
        </div>

        <flux:dropdown x-ref="messageDropdown" data-message-dropdown align="{{ $own ? 'end' : 'start' }}" @class(['chat-message-actions absolute bottom-0', 'end-full me-1' => $own, 'start-full ms-1' => ! $own])>
            <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" square :aria-label="__('Действия с сообщением')" data-test="message-actions" />
            <flux:menu data-message-menu>
                <div class="grid grid-cols-4 gap-1" role="group" aria-label="{{ __('Выберите реакцию') }}" data-reaction-picker>
                    @foreach (\App\Models\Message::REACTION_EMOJIS as $emoji)
                        <flux:menu.item wire:click="toggleReaction({{ $message['id'] }}, @js($emoji))" class="justify-center text-xl" aria-label="{{ __('Реакция :emoji', ['emoji' => $emoji]) }}" data-test="pick-message-reaction">{{ $emoji }}</flux:menu.item>
                    @endforeach
                </div>
                <flux:separator class="my-1" />
                <flux:menu.item icon="arrow-uturn-left" wire:click="replyToMessage({{ $message['id'] }})">{{ __('Ответить') }}</flux:menu.item>
                <flux:menu.item icon="arrow-uturn-right" wire:click="openForwardMessage({{ $message['id'] }})">{{ __('Переслать') }}</flux:menu.item>
                @if ($own && $isGroupChat)
                    <flux:menu.item icon="eye" wire:click="showMessageReaders({{ $message['id'] }})">{{ __('Просмотрели') }}</flux:menu.item>
                @endif
            </flux:menu>
        </flux:dropdown>

        {{-- Реакции --}}
        @if (filled($message['reactions'] ?? []))
            <div class="flex max-w-full flex-wrap items-center gap-1" data-test="message-reactions">
                @foreach ($message['reactions'] ?? [] as $reaction)
                    <button
                        type="button"
                        wire:key="reaction-{{ $message['id'] }}-{{ $reaction['emoji'] }}"
                        wire:click="toggleReaction({{ $message['id'] }}, @js($reaction['emoji']))"
                        wire:loading.attr="disabled"
                        wire:target="toggleReaction"
                        aria-pressed="{{ $reaction['mine'] ? 'true' : 'false' }}"
                        aria-label="{{ $reaction['emoji'] }}: {{ $reaction['count'] }}{{ $reaction['mine'] ? __('. Убрать реакцию') : __('. Добавить реакцию') }}"
                        title="{{ $reaction['names'] }}"
                        data-test="message-reaction"
                        @class([
                            'flex min-h-7 items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition disabled:opacity-50',
                            'border-sky-400 bg-sky-100 text-sky-700 dark:border-sky-600 dark:bg-sky-950 dark:text-sky-200' => $reaction['mine'],
                            'border-zinc-200 bg-white hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-700/60 dark:hover:bg-zinc-700' => ! $reaction['mine'],
                        ])
                    >
                        <span>{{ $reaction['emoji'] }}</span>
                        <span class="font-medium">{{ $reaction['count'] }}</span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>
</div>
