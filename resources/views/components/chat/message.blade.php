@props([
    'message' => [],
    'messageIndex' => 0,
])

@php
    $own = $message['own'];
    $author = $own ? auth()->user()->name : $message['author'];
@endphp

<div {{ $attributes->class(['flex items-end gap-2.5', 'flex-row-reverse' => $own]) }}>
    {{-- Аватар автора --}}
    @unless ($own)
        @if ($message['first_of_group'])
            <flux:avatar class="mb-5 shrink-0" :name="$author" color="auto" size="sm" />
        @else
            <span class="w-8 shrink-0" aria-hidden="true"></span>
        @endif
    @endunless

    <div @class([
        'flex min-w-0 max-w-[min(38rem,85%)] flex-col gap-1',
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
            'w-fit max-w-full rounded-2xl px-3.5 py-2.5 text-sm shadow-xs',
            'bg-accent text-accent-foreground' => $own,
            'bg-zinc-100 text-zinc-800 dark:bg-zinc-700/60 dark:text-zinc-100' => ! $own,
        ])>
            <p class="break-words whitespace-pre-line">{{ $message['body'] }}</p>

            {{-- Вложение --}}
            @if (filled($message['attachment'] ?? null))
                <div class="mt-2 flex items-center gap-3 rounded-xl bg-white/70 p-2.5 dark:bg-black/20">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-200 text-zinc-600 dark:bg-zinc-600 dark:text-zinc-100">
                        <flux:icon.document class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-medium">{{ $message['attachment']['name'] }}</span>
                        <span class="block text-[11px] opacity-70">{{ $message['attachment']['kind'] }} · {{ $message['attachment']['size'] }}</span>
                    </span>
                    <flux:icon.arrow-down-tray class="size-4 shrink-0 opacity-60" />
                </div>
            @endif

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
            <span class="mt-1 flex items-center justify-end gap-1.5 text-[10px] opacity-70">
                {{ $message['at'] }}

                @if ($own)
                    <span class="flex items-center -space-x-1.5" title="{{ __('Прочитано') }}">
                        <flux:icon.check class="size-3" />
                        <flux:icon.check class="size-3" />
                        <span class="sr-only">{{ __('Прочитано') }}</span>
                    </span>
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
