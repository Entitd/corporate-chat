@props([
    'chats' => [],
    'activeChatId' => null,
    'chatFilter' => 'all',
    'unreadTotal' => 0,
    'directCount' => 0,
    'groupCount' => 0,
    'mentionNotifications' => [],
])

@php
    $tabs = [
        ['key' => 'all', 'label' => __('Все'), 'count' => $directCount + $groupCount],
        ['key' => 'direct', 'label' => __('Личные'), 'count' => $directCount],
        ['key' => 'group', 'label' => __('Группы'), 'count' => $groupCount],
    ];
    $mentionUnreadCount = collect($mentionNotifications)->where('read', false)->count();
@endphp

<div class="relative flex h-full min-h-0 flex-col">
    {{-- Шапка --}}
    <div class="flex items-center gap-2 px-4 pb-2 pt-4 lg:gap-1 lg:border-b lg:border-zinc-200 lg:px-3 lg:py-3 dark:lg:border-zinc-700">
        <div class="lg:hidden">
            <x-chat.user-menu mobile />
        </div>

        <a
            href="{{ route('dashboard') }}"
            wire:navigate
            class="hidden min-w-0 items-center gap-2 rounded-lg p-1 hover:bg-zinc-800/5 lg:flex dark:hover:bg-white/10"
        >
            <x-app-logo-icon class="size-6" />
            <span class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ config('app.name', 'Laravel') }}</span>
        </a>

        <h1 class="min-w-0 flex-1 truncate text-2xl font-bold tracking-tight text-zinc-900 lg:hidden dark:text-white">{{ __('Чаты') }}</h1>

        <flux:tooltip :content="__('Новый чат')" class="ms-auto hidden lg:block">
            <flux:button
                size="sm"
                variant="ghost"
                icon="plus"
                square
                wire:click="$set('showNewChatModal', true)"
                data-test="new-chat-button"
            />
        </flux:tooltip>

        <div wire:poll.15s>
            <flux:dropdown position="bottom" align="end">
                <span class="relative inline-flex">
                    <flux:button size="sm" variant="ghost" icon="bell" square :aria-label="__('Упоминания')" class="text-sky-600 dark:text-sky-400" data-test="mention-notifications-button" />
                    @if ($mentionUnreadCount > 0)
                        <span class="pointer-events-none absolute -end-1 -top-1 flex min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] leading-4 text-white">{{ $mentionUnreadCount }}</span>
                    @endif
                </span>

                <flux:menu class="min-w-72 max-w-sm">
                    <div class="px-3 py-2 text-xs font-semibold text-zinc-500">{{ __('Упоминания') }}</div>
                    @forelse ($mentionNotifications as $notification)
                        <flux:menu.item wire:key="mention-notification-{{ $notification['id'] }}" wire:click="openMentionNotification('{{ $notification['id'] }}')" data-test="mention-notification" class="whitespace-normal">
                            <span @class(['block text-sm', 'font-semibold' => ! $notification['read']])>{{ __('Вас упомянул :name', ['name' => $notification['author_name']]) }}</span>
                            <span class="block truncate text-xs text-zinc-500">{{ $notification['chat_name'] }} · {{ $notification['excerpt'] }}</span>
                        </flux:menu.item>
                    @empty
                        <p class="px-3 py-3 text-xs text-zinc-500">{{ __('Упоминаний пока нет') }}</p>
                    @endforelse
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    {{-- Поиск --}}
    <div class="px-4 pt-2 lg:px-3 lg:pt-3 [&_[data-flux-input]]:rounded-xl">
        <flux:input
            wire:model.live.debounce.300ms="search"
            size="sm"
            icon="magnifying-glass"
            clearable
            :placeholder="__('Поиск по чатам и людям')"
            data-test="chat-search"
        />
    </div>

    {{-- Фильтры --}}
    <div class="flex items-center gap-1 border-b border-zinc-100 px-4 pt-2 lg:border-0 lg:px-3 lg:py-3 dark:border-zinc-800">
        @foreach ($tabs as $tab)
            <button
                type="button"
                wire:key="filter-{{ $tab['key'] }}"
                wire:click="setFilter('{{ $tab['key'] }}')"
                @class([
                    'flex flex-1 items-center justify-center gap-1.5 border-b-2 px-2 py-2.5 text-xs font-semibold transition lg:rounded-lg lg:border-b-0 lg:py-1.5',
                    'border-sky-500 text-sky-600 lg:bg-white lg:text-zinc-900 lg:shadow-xs dark:text-sky-400 dark:lg:bg-white/15 dark:lg:text-white' => $chatFilter === $tab['key'],
                    'border-transparent text-zinc-500 hover:bg-zinc-800/5 dark:text-zinc-400 dark:hover:bg-white/10' => $chatFilter !== $tab['key'],
                ])
            >
                {{ $tab['label'] }}
                <span class="text-[11px] opacity-60">{{ $tab['count'] }}</span>
            </button>
        @endforeach
    </div>

    {{-- Список чатов --}}
    <div class="min-h-0 flex-1 overflow-y-auto pb-20 lg:px-2 lg:pb-2" data-test="chat-list">
        @forelse ($chats as $chat)
            <div class="group relative" wire:key="chat-{{ $chat['id'] }}">
                <button
                    type="button"
                    wire:click="selectChat({{ $chat['id'] }})"
                    @class([
                        'flex w-full items-center gap-3 px-4 py-3 text-start transition lg:items-start lg:rounded-xl lg:px-2 lg:py-2',
                        'hover:bg-sky-50/70 lg:bg-zinc-200/70 lg:hover:bg-zinc-200/70 dark:hover:bg-white/5 dark:lg:bg-white/10' => $chat['id'] === $activeChatId,
                        'hover:bg-zinc-50 lg:hover:bg-zinc-800/5 dark:hover:bg-white/5' => $chat['id'] !== $activeChatId,
                    ])
                >
                    {{-- Аватар --}}
                    <span class="relative shrink-0">
                        <x-chat.avatar :chat="$chat" size="lg" class="lg:hidden" />
                        <x-chat.avatar :chat="$chat" class="hidden lg:inline-flex" />
                    </span>

                    {{-- Текст --}}
                    <span class="min-w-0 flex-1 border-b border-zinc-100 py-1 lg:border-0 lg:py-0 dark:border-zinc-800">
                        <span class="flex items-center gap-1.5">
                            <span class="min-w-0 flex-1 truncate text-[15px] font-semibold text-zinc-900 lg:text-sm lg:font-medium dark:text-white">{{ $chat['name'] }}</span>
                            @if ($chat['last_message'])
                                <span class="shrink-0 text-[11px] text-zinc-400 lg:hidden">{{ $chat['last_message']['time'] }}</span>
                            @endif
                        </span>

                        <span class="mt-1 flex min-w-0 items-center gap-2 lg:mt-0.5">
                            <span class="min-w-0 flex-1 truncate text-[13px] text-zinc-500 lg:text-xs dark:text-zinc-400">
                                @if ($chat['last_message'])
                                    @if ($chat['type'] === 'group')<span class="text-sky-600 dark:text-sky-400">{{ $chat['last_message']['author'] }}:</span> @endif{{ $chat['last_message']['text'] }}
                                @else
                                    {{ __('Сообщений пока нет') }}
                                @endif
                            </span>
                        </span>
                    </span>
                </button>

                {{-- Действия с чатом (только там, где есть курсор) --}}
                <div class="absolute end-1.5 top-1/2 hidden -translate-y-1/2 transition lg:block lg:pointer-events-none lg:opacity-0 lg:group-hover:pointer-events-auto lg:group-hover:opacity-100 lg:group-focus-within:pointer-events-auto lg:group-focus-within:opacity-100">
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" square />

                        <flux:menu>
                            @if ($chat['type'] === 'group')
                                <flux:menu.item icon="user-plus" data-test="invite-colleague-menu-item">{{ __('Пригласить коллегу') }}</flux:menu.item>
                            @endif
                            <flux:menu.item icon="x-mark" variant="danger">{{ __('Покинуть чат') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>
        @empty
            <div class="px-3 py-10 text-center">
                <flux:icon.magnifying-glass class="mx-auto size-6 text-zinc-400" />
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Чаты не найдены') }}</p>
                <p class="mt-1 text-xs text-zinc-400">{{ __('Измените запрос или фильтр') }}</p>
            </div>
        @endforelse
    </div>

    {{-- Новый чат на мобильных --}}
    <div class="absolute bottom-5 end-5 z-10 lg:hidden">
        <button type="button" wire:click="$set('showNewChatModal', true)" class="flex size-14 items-center justify-center rounded-full bg-sky-500 text-white shadow-lg shadow-sky-500/25 transition hover:bg-sky-600" aria-label="{{ __('Новый чат') }}" data-test="new-chat-button-mobile">
            <flux:icon.pencil-square class="size-6" />
        </button>
    </div>

    <div class="hidden items-center gap-2 border-t border-zinc-200 p-2 lg:flex dark:border-zinc-700">
        <x-chat.user-menu />

        @if ($unreadTotal > 0)
            <flux:tooltip :content="__('Отметить всё прочитанным')">
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="check"
                    square
                    wire:click="markAllAsRead"
                    data-test="mark-all-read-button"
                />
            </flux:tooltip>
        @endif
    </div>
</div>
