@props([
    'chat' => [],
    'participants' => [],
    'files' => [],
    'links' => [],
])

<aside class="fixed inset-0 z-30 flex flex-col border-s border-zinc-200 bg-white lg:static lg:z-auto lg:w-80 lg:shrink-0 dark:border-zinc-700 dark:bg-zinc-900">
    {{-- Шапка --}}
    <div class="flex h-16 shrink-0 items-center gap-2 border-b border-zinc-200 px-3 dark:border-zinc-700">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('О чате') }}</h2>

        <flux:button
            class="ms-auto"
            size="sm"
            variant="ghost"
            icon="x-mark"
            square
            wire:click="toggleDetails"
            :aria-label="__('Закрыть')"
        />
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto">
        {{-- Карточка чата --}}
        <div class="flex flex-col items-center gap-3 px-4 py-6 text-center">
            <x-chat.avatar :chat="$chat" size="lg" />

            <div>
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $chat['title'] }}</p>
                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $chat['subtitle'] }}</p>

                @if ($chat['type'] === 'direct')
                    <p class="mt-1 text-xs {{ $chat['online'] ? 'text-green-600 dark:text-green-400' : 'text-zinc-400' }}">
                        {{ $chat['presence'] ?? $chat['subtitle'] }}
                    </p>
                @endif
            </div>

            <div class="flex items-center gap-1">
                <flux:tooltip :content="__('Отключить уведомления')">
                    <flux:button size="sm" variant="ghost" icon="bell-slash" square />
                </flux:tooltip>

                <flux:tooltip :content="__('Поиск в чате')">
                    <flux:button size="sm" variant="ghost" icon="magnifying-glass" square />
                </flux:tooltip>

                <flux:tooltip :content="__('Пригласить коллегу')">
                    <flux:button size="sm" variant="ghost" icon="user-plus" square />
                </flux:tooltip>

                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" square />

                    <flux:menu>
                        <flux:menu.item icon="map-pin">{{ __('Закрепить чат') }}</flux:menu.item>
                        <flux:menu.item icon="star">{{ __('В избранное') }}</flux:menu.item>
                        <flux:menu.separator />
                        <flux:menu.item icon="arrow-right-start-on-rectangle" variant="danger">{{ __('Покинуть чат') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </div>

        <flux:separator />

        {{-- Участники --}}
        <div class="px-2 py-3">
            <div class="flex items-center justify-between px-2 pb-2">
                <span class="text-[11px] font-semibold tracking-wide text-zinc-400 uppercase">{{ __('Участники') }}</span>
                <span class="text-xs text-zinc-400">{{ count($participants) }}</span>
            </div>

            @foreach ($participants as $participant)
                <div
                    class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-zinc-800/5 dark:hover:bg-white/5"
                    wire:key="participant-{{ $loop->index }}"
                >
                    <span class="relative shrink-0">
                        <flux:avatar :name="$participant['name']" color="auto" size="sm" />

                        @if ($participant['online'])
                            <span class="absolute -end-0.5 -bottom-0.5 size-2.5 rounded-full border-2 border-white bg-green-500 dark:border-zinc-900"></span>
                        @endif
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm text-zinc-900 dark:text-white">{{ $participant['name'] }}</span>
                        <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $participant['position'] }}</span>
                    </span>
                </div>
            @endforeach
        </div>

        <flux:separator />

        {{-- Общие файлы --}}
        <div class="px-2 py-3">
            <div class="flex items-center justify-between px-2 pb-2">
                <span class="text-[11px] font-semibold tracking-wide text-zinc-400 uppercase">{{ __('Общие файлы') }}</span>
                <span class="text-xs text-zinc-400">{{ count($files) }}</span>
            </div>

            @forelse ($files as $file)
                <div class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-zinc-800/5 dark:hover:bg-white/5" wire:key="file-{{ $loop->index }}">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-300">
                        <flux:icon.document class="size-4" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-medium text-zinc-900 dark:text-white">{{ $file['name'] }}</span>
                        <span class="block truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ $file['author'] }} · {{ $file['size'] }}</span>
                    </span>

                    <flux:icon.arrow-down-tray class="size-4 shrink-0 text-zinc-400" />
                </div>
            @empty
                <p class="px-2 py-2 text-xs text-zinc-400">{{ __('В этом чате ещё нет файлов') }}</p>
            @endforelse
        </div>

        <flux:separator />

        {{-- Общие ссылки --}}
        <div class="px-2 py-3">
            <div class="flex items-center justify-between px-2 pb-2">
                <span class="text-[11px] font-semibold tracking-wide text-zinc-400 uppercase">{{ __('Общие ссылки') }}</span>
                <span class="text-xs text-zinc-400">{{ count($links) }}</span>
            </div>

            @forelse ($links as $link)
                <a
                    href="{{ $link['url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-zinc-800/5 dark:hover:bg-white/5"
                    wire:key="link-{{ $loop->index }}"
                >
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-300">
                        <flux:icon.link class="size-4" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-xs font-medium text-zinc-900 dark:text-white">{{ $link['title'] }}</span>
                        <span class="block truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ $link['host'] }} · {{ $link['at'] }}</span>
                    </span>
                </a>
            @empty
                <p class="px-2 py-2 text-xs text-zinc-400">{{ __('В этом чате ещё нет ссылок') }}</p>
            @endforelse
        </div>
    </div>
</aside>
