@props(['attachment', 'compact' => false])

@php
    $previewable = in_array($attachment['file_type'], \App\Models\Attachment::PREVIEW_TYPES, true);
    $isImage = $previewable && str_starts_with($attachment['file_type'], 'image/');
    $download = route('chat.attachments.download', $attachment['id']);
    $preview = route('chat.attachments.preview', $attachment['id']);
@endphp

@if ($isImage && ! $compact)
    <div x-data="{ failed: false }" class="group relative isolate mt-2 w-72 max-w-full overflow-hidden rounded-2xl bg-zinc-200 shadow-sm ring-1 ring-black/5 dark:bg-zinc-800 dark:ring-white/10" data-test="message-attachment">
        <a href="{{ $preview }}" class="block aspect-[4/3] w-full overflow-hidden focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-white"
            x-on:click.prevent="$dispatch('open-attachment-preview', @js(['url' => $preview, 'download' => $download, 'name' => $attachment['file_name'], 'image' => $isImage]))"
            aria-label="{{ __('Открыть :name', ['name' => $attachment['file_name']]) }}" data-test="attachment-preview-link" data-chat-photo="{{ json_encode(['url' => $preview, 'download' => $download, 'name' => $attachment['file_name'], 'image' => true]) }}">
            <img src="{{ $preview }}" alt="{{ $attachment['file_name'] }}" loading="lazy" decoding="async"
                x-show="!failed" x-on:error="failed = true" class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.03] motion-reduce:transform-none motion-reduce:transition-none" data-test="attachment-image-preview" />
            <span x-show="failed" x-cloak class="flex h-full flex-col items-center justify-center gap-2 px-4 pb-10 text-center text-xs text-zinc-500 dark:text-zinc-400">
                <flux:icon.photo class="size-8" />
                {{ __('Не удалось загрузить изображение') }}
            </span>
            <span class="pointer-events-none absolute inset-x-0 bottom-0 bg-linear-to-t from-black/75 via-black/35 to-transparent px-3 pb-3 pt-10 text-white">
                <span class="block truncate text-xs font-medium">{{ $attachment['file_name'] }}</span>
                <span class="mt-0.5 block text-[10px] text-white/70">{{ number_format(($attachment['file_size'] ?? 0) / 1024, 1) }} КБ</span>
            </span>
        </a>
        <a href="{{ $download }}" class="absolute end-2 top-2 flex size-9 items-center justify-center rounded-full bg-black/35 text-white shadow-sm ring-1 ring-white/15 backdrop-blur-md transition-colors hover:bg-black/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white" aria-label="{{ __('Скачать :name', ['name' => $attachment['file_name']]) }}" title="{{ __('Скачать') }}" data-test="attachment-download-link">
            <flux:icon.arrow-down-tray class="size-4" />
        </a>
    </div>
@else
<div @class(['flex min-w-0 items-center gap-1 rounded-xl', 'mt-2 bg-white/70 dark:bg-black/20' => ! $compact, 'hover:bg-zinc-800/5 dark:hover:bg-white/5' => $compact]) data-test="message-attachment">
    <a href="{{ $previewable ? $preview : $download }}"
        @if ($previewable)
            x-on:click.prevent="$dispatch('open-attachment-preview', @js(['url' => $preview, 'download' => $download, 'name' => $attachment['file_name'], 'image' => $isImage]))"
        @endif
        class="flex min-w-0 flex-1 items-center gap-3 rounded-xl p-2.5 text-inherit"
        @if ($previewable) data-test="attachment-preview-link" @endif
    >
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-200 text-zinc-600 dark:bg-zinc-600 dark:text-zinc-100">
            @if ($isImage)
                <flux:icon.photo class="size-4" />
            @else
                <flux:icon.document class="size-4" />
            @endif
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-xs font-medium">{{ $attachment['file_name'] }}</span>
            <span class="block text-[11px] opacity-70">{{ $attachment['author'] ?? $attachment['file_type'] }} · {{ number_format(($attachment['file_size'] ?? 0) / 1024, 1) }} КБ</span>
        </span>
    </a>
    <a href="{{ $download }}" class="m-1 shrink-0 rounded-lg p-2 hover:bg-black/5 dark:hover:bg-white/10" aria-label="{{ __('Скачать :name', ['name' => $attachment['file_name']]) }}" title="{{ __('Скачать') }}" data-test="attachment-download-link">
        <flux:icon.arrow-down-tray class="size-4 opacity-60" />
    </a>
</div>
@endif
