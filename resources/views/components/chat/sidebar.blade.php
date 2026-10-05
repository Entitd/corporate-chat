@props([
    'chats' => [],
    'activeChatId' => null,
    'chatFilter' => 'all',
    'unreadTotal' => 0,
    'directCount' => 0,
    'groupCount' => 0,
])

@php
    $tabs = [
        ['key' => 'all', 'label' => __('Все'), 'count' => $directCount + $groupCount],
        ['key' => 'direct', 'label' => __('Личные'), 'count' => $directCount],
        ['key' => 'group', 'label' => __('Группы'), 'count' => $groupCount],
    ];
@endphp

<div class="flex h-full min-h-0 flex-col">
    {{-- Шапка --}}
    <div class="flex items-center gap-1 border-b border-zinc-200 px-3 py-3 dark:border-zinc-700">
        <a
            href="{{ route('dashboard') }}"
            wire:navigate
            class="flex min-w-0 items-center gap-2 rounded-lg p-1 hover:bg-zinc-800/5 dark:hover:bg-white/10"
        >
            <x-app-logo-icon class="size-6" />
            <span class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ config('app.name', 'Laravel') }}</span>
        </a>

        <flux:tooltip :content="__('Новый чат')" class="ms-auto">
            <flux:button
                size="sm"
                variant="ghost"
                icon="plus"
                square
                wire:click="$set('showNewChatModal', true)"
                data-test="new-chat-button"
            />
        </flux:tooltip>
    </div>

    {{-- Поиск --}}
    <div class="px-3 pt-3">
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
    <div class="flex items-center gap-1 px-3 py-3">
        @foreach ($tabs as $tab)
            <button
                type="button"
                wire:key="filter-{{ $tab['key'] }}"
                wire:click="setFilter('{{ $tab['key'] }}')"
                @class([
                    'flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-medium transition',
                    'bg-white text-zinc-900 shadow-xs dark:bg-white/15 dark:text-white' => $chatFilter === $tab['key'],
                    'text-zinc-500 hover:bg-zinc-800/5 dark:text-zinc-400 dark:hover:bg-white/10' => $chatFilter !== $tab['key'],
                ])
            >
                {{ $tab['label'] }}
                <span class="text-[11px] opacity-60">{{ $tab['count'] }}</span>
            </button>
        @endforeach
    </div>

    {{-- Список чатов --}}
    <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-2">
        @forelse ($chats as $chat)
            <div class="group relative" wire:key="chat-{{ $chat['id'] }}">
                <button
                    type="button"
                    wire:click="selectChat({{ $chat['id'] }})"
                    @class([
                        'flex w-full items-start gap-3 rounded-xl px-2 py-2 text-start transition',
                        'bg-zinc-200/70 dark:bg-white/10' => $chat['id'] === $activeChatId,
                        'hover:bg-zinc-800/5 dark:hover:bg-white/5' => $chat['id'] !== $activeChatId,
                    ])
                >
                    {{-- Аватар --}}
                    <span class="relative shrink-0">
                        <x-chat.avatar :chat="$chat" />

                        {{-- @if ($chat['type'] === 'direct' && $chat['online'])
                            <span class="absolute -end-0.5 -bottom-0.5 size-3 rounded-full border-2 border-zinc-50 bg-green-500 dark:border-zinc-900"></span>
                        @endif --}}
                    </span>

                    {{-- Текст --}}
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $chat['name'] }}</span>
                            {{-- @if ($chat['pinned'])
                                <flux:icon.map-pin class="size-3.5 shrink-0 text-zinc-400" />
                            @endif --}}

                            {{-- @if ($chat['muted'])
                                <flux:icon.bell-slash class="size-3.5 shrink-0 text-zinc-400" />
                            @endif --}}

                            {{-- <span @class([
                                'ms-auto shrink-0 text-[11px]',
                                'font-semibold text-zinc-900 dark:text-white' => $chat['unread'] > 0,
                                'text-zinc-400' => $chat['unread'] === 0,
                            ])>{{ $chat['last_message']['at'] }}</span> --}}
                        </span>

                        <span class="mt-0.5 flex items-center gap-2">
                            {{-- <span class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($chat['type'] === 'group')
                                    {{ $chat['last_message']['author'] }}:
                                @endif
                                {{ $chat['last_message']['text'] }}
                            </span> --}}

                            {{-- @if ($chat['unread'] > 0)
                                <span class="ms-auto flex size-5 shrink-0 items-center justify-center rounded-full bg-accent text-[11px] font-semibold text-accent-foreground">{{ $chat['unread'] }}</span>
                            @endif --}}
                        </span>
                    </span>
                </button>

                {{-- Действия с чатом (только там, где есть курсор) --}}
                <div class="absolute end-1.5 top-1/2 hidden -translate-y-1/2 transition lg:block lg:pointer-events-none lg:opacity-0 lg:group-hover:pointer-events-auto lg:group-hover:opacity-100 lg:group-focus-within:pointer-events-auto lg:group-focus-within:opacity-100">
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" square />

                        <flux:menu>
                            {{-- <flux:menu.item icon="check" wire:click="selectChat({{ $chat['id'] }})">
                                {{ $chat['unread'] > 0 ? __('Отметить прочитанным') : __('Открыть чат') }}
                            </flux:menu.item> --}}
                            {{-- <flux:menu.item icon="bell-slash">{{ $chat['muted'] ? __('Включить уведомления') : __('Отключить уведомления') }}</flux:menu.item> --}}
                            {{-- <flux:menu.item icon="map-pin">{{ $chat['pinned'] ? __('Открепить') : __('Закрепить') }}</flux:menu.item> --}}
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

    {{-- Текущий пользователь --}}
    <div class="flex items-center gap-2 border-t border-zinc-200 p-2 dark:border-zinc-700">
        <flux:dropdown position="top" align="start" class="min-w-0 flex-1">
            <flux:profile
                class="w-full"
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
                icon:trailing="chevrons-up-down"
                data-test="sidebar-menu-button"
            />

            <flux:menu>
                <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                    <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />
                    <div class="grid flex-1 text-start text-sm leading-tight">
                        <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                        <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                    </div>
                </div>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('dashboard')" icon="home" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:menu.item>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item
                        as="button"
                        type="submit"
                        icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer"
                        data-test="logout-button"
                    >
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>

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
