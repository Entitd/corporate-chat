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

<div {{ $attributes->class(['flex items-end gap-1.5 lg:gap-2.5', 'flex-row-reverse' => $own]) }}>
    {{-- Аватар автора --}}
    @unless ($own)
        @if ($message['first_of_group'])
            <flux:avatar class="mb-5 hidden shrink-0 lg:inline-flex" :name="$author" color="auto" size="sm" />
        @else
            <span class="hidden w-8 shrink-0 lg:block" aria-hidden="true"></span>
        @endif
    @endunless

    <div @class([
        'flex min-w-0 max-w-[85%] flex-col gap-1 lg:max-w-[min(38rem,85%)]',
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
        <div @class([
            'w-fit max-w-full rounded-2xl px-3 py-2 text-sm shadow-xs lg:px-3.5 lg:py-2.5',
            'rounded-br-md bg-[#d9fdd3] text-zinc-900 lg:rounded-br-2xl lg:bg-accent lg:text-accent-foreground dark:bg-[#334d42] dark:text-white dark:lg:bg-accent dark:lg:text-accent-foreground' => $own,
            'rounded-bl-md bg-white text-zinc-800 lg:rounded-bl-2xl lg:bg-zinc-100 dark:bg-zinc-700 dark:text-zinc-100 dark:lg:bg-zinc-700/60' => ! $own,
        ])>
            @if (filled($message['body']))
                <p class="break-words whitespace-pre-line">@foreach ($message['body_segments'] as $segment)@if ($segment['user_id'])<a href="{{ route('chat.members.show', ['chat' => $message['chat_id'], 'user' => $segment['user_id']]) }}" wire:click.prevent="showMentionProfile({{ $segment['user_id'] }})" @class(['rounded px-0.5 font-semibold underline underline-offset-2 hover:opacity-75', 'bg-white/20 text-sky-700 lg:text-accent-foreground dark:text-sky-300 dark:lg:text-accent-foreground' => $own, 'bg-blue-500/15 text-blue-700 dark:text-blue-300' => ! $own]) data-test="message-mention-link">{{ $segment['text'] }}</a>@else{{ $segment['text'] }}@endif@endforeach</p>
            @endif

            {{-- Вложения --}}
            @foreach ($message['attachments'] ?? [] as $attachment)
                <a href="{{ route('chat.attachments.download', $attachment['id']) }}" class="mt-2 flex items-center gap-3 rounded-xl bg-white/70 p-2.5 text-inherit dark:bg-black/20" data-test="message-attachment">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-200 text-zinc-600 dark:bg-zinc-600 dark:text-zinc-100">
                        <flux:icon.document class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-medium">{{ $attachment['file_name'] }}</span>
                        <span class="block text-[11px] opacity-70">{{ $attachment['file_type'] }} · {{ number_format(($attachment['file_size'] ?? 0) / 1024, 1) }} КБ</span>
                    </span>
                    <flux:icon.arrow-down-tray class="size-4 shrink-0 opacity-60" />
                </a>
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

        {{-- Реакции --}}
        @if (filled($message['reactions'] ?? []))
            <div class="flex flex-wrap items-center gap-1">
                @foreach ($message['reactions'] as $reaction)
                    <button
                        type="button"
                        wire:key="reaction-{{ $messageIndex }}-{{ $loop->index }}"
                        @class([
                            'flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition',
                            'border-accent/40 bg-accent/10' => $reaction['mine'],
                            'border-zinc-200 bg-white hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-700/60 dark:hover:bg-zinc-700' => ! $reaction['mine'],
                        ])
                    >
                        <span>{{ $reaction['emoji'] }}</span>
                        <span class="font-medium">{{ $reaction['count'] }}</span>
                    </button>
                @endforeach

                <button
                    type="button"
                    wire:key="reaction-add-{{ $messageIndex }}"
                    class="flex items-center rounded-full border border-dashed border-zinc-300 px-2 py-0.5 text-xs text-zinc-400 hover:border-zinc-400 hover:text-zinc-500 dark:border-zinc-600"
                    title="{{ __('Добавить реакцию') }}"
                >
                    <flux:icon.face-smile class="size-3.5" />
                </button>
            </div>
        @endif
    </div>
</div>
