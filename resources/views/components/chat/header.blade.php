@props([
    'chat' => [],
    'participantsCount' => 0,
    'showDetails' => false,
])

<div class="flex h-16 shrink-0 items-center gap-3 border-b border-zinc-200 bg-white px-3 dark:border-zinc-700 dark:bg-zinc-800">
    {{-- Возврат к списку чатов (мобильные) --}}
    <flux:button
        class="lg:hidden"
        size="sm"
        variant="ghost"
        icon="arrow-left"
        square
        wire:click="backToList"
        data-test="back-to-chats"
    />

    {{-- Аватар и название --}}
    <span class="relative shrink-0">
        <x-chat.avatar :chat="$chat" />

        {{-- @if ($chat['type'] === 'direct' && $chat['online'])
            <span class="absolute -end-0.5 -bottom-0.5 size-3 rounded-full border-2 border-white bg-green-500 dark:border-zinc-800"></span>
        @endif --}}
    </span>

    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
            <h1 class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $chat['name'] }}</h1>

            @if ($chat['type'] === 'group')
                <span class="max-sm:hidden">
                    <flux:badge size="sm" class="shrink-0">{{ __(':count участников', ['count' => $participantsCount]) }}</flux:badge>
                </span>
            @endif
        </div>

        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
            @if (filled($chat['typing'] ?? null))
                <span class="text-green-600 dark:text-green-400">{{ $chat['typing'] }} {{ __('печатает…') }}</span>
            @elseif ($chat['type'] === 'group')
                {{ $chat['subtitle'] }}
            {{-- @else
                {{ $chat['presence'] ?? $chat['subtitle'] }} --}}
            @endif
        </p>
    </div>

    {{-- Действия --}}
    <div class="flex items-center gap-0.5">
        {{-- Звонки и поиск прячем на узких экранах, чтобы не обрезать название чата --}}
        <div class="hidden items-center gap-0.5 sm:flex">
            <flux:tooltip :content="__('Аудиозвонок')">
                <flux:button size="sm" variant="ghost" icon="phone" square />
            </flux:tooltip>

            <flux:tooltip :content="__('Видеовстреча')">
                <flux:button size="sm" variant="ghost" icon="video-camera" square />
            </flux:tooltip>

            <flux:tooltip :content="__('Поиск в чате')">
                <flux:button size="sm" variant="ghost" icon="magnifying-glass" square />
            </flux:tooltip>
        </div>

        <flux:tooltip :content="__('О чате')">
            <flux:button
                size="sm"
                variant="ghost"
                icon="information-circle"
                square
                wire:click="toggleDetails"
                :class="$showDetails ? 'bg-zinc-800/5 dark:bg-white/15' : ''"
                data-test="toggle-details"
            />
        </flux:tooltip>

        <flux:dropdown position="bottom" align="end">
            <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" square />

            <flux:menu>
                <flux:menu.item icon="user-group">{{ __('Участники') }}</flux:menu.item>
                <flux:menu.item icon="bell-slash">{{ __('Отключить уведомления') }}</flux:menu.item>
                <flux:menu.item icon="map-pin">{{ __('Закрепить чат') }}</flux:menu.item>
                <flux:menu.separator />
                <flux:menu.item icon="document">{{ __('Общие файлы') }}</flux:menu.item>
                <flux:menu.item icon="link">{{ __('Общие ссылки') }}</flux:menu.item>
                <flux:menu.separator />
                <flux:menu.item icon="arrow-right-start-on-rectangle" variant="danger">{{ __('Покинуть чат') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </div>
</div>
