@props([
    'colleagues' => [],
    'mode' => 'direct',
    'selected' => [],
])

<flux:modal wire:model="showNewChatModal" class="md:w-[26rem]" data-test="new-chat-modal">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">{{ __('Новый чат') }}</flux:heading>
            <flux:subheading>{{ __('Выберите коллегу для личной переписки или соберите группу') }}</flux:subheading>
        </div>

        {{-- Режим создания --}}
        <div class="flex items-center gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-700/50">
            <button
                type="button"
                wire:click="setNewChatMode('direct')"
                @class([
                    'flex-1 rounded-lg px-3 py-1.5 text-xs font-medium transition',
                    'bg-white text-zinc-900 shadow-xs dark:bg-zinc-800 dark:text-white' => $mode === 'direct',
                    'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $mode !== 'direct',
                ])
            >
                {{ __('Личный чат') }}
            </button>

            <button
                type="button"
                wire:click="setNewChatMode('group')"
                @class([
                    'flex-1 rounded-lg px-3 py-1.5 text-xs font-medium transition',
                    'bg-white text-zinc-900 shadow-xs dark:bg-zinc-800 dark:text-white' => $mode === 'group',
                    'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' => $mode !== 'group',
                ])
            >
                {{ __('Группа') }}
            </button>
        </div>

        <flux:input
            wire:model.live.debounce.300ms="colleagueSearch"
            size="sm"
            icon="magnifying-glass"
            :placeholder="__('Имя, должность или отдел')"
        />

        {{-- Коллеги --}}
        <div class="-mx-1 max-h-72 space-y-0.5 overflow-y-auto px-1">
            @forelse ($colleagues as $colleague)
                @if ($mode === 'group')
                    <div
                        class="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-zinc-800/5 dark:hover:bg-white/5"
                        wire:key="colleague-{{ $loop->index }}"
                    >
                        <flux:checkbox
                            wire:model.live="selectedColleagues"
                            value="{{ $colleague['name'] }}"
                            aria-label="{{ $colleague['name'] }}"
                        />

                        <span class="relative shrink-0">
                            <flux:avatar :name="$colleague['name']" color="auto" size="sm" />

                            @if ($colleague['online'])
                                <span class="absolute -end-0.5 -bottom-0.5 size-2.5 rounded-full border-2 border-white bg-green-500 dark:border-zinc-800"></span>
                            @endif
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-zinc-900 dark:text-white">{{ $colleague['name'] }}</span>
                            <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $colleague['position'] }}</span>
                        </span>
                    </div>
                @else
                    <div
                        class="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-zinc-800/5 dark:hover:bg-white/5"
                        wire:key="colleague-{{ $loop->index }}"
                    >
                        <span class="relative shrink-0">
                            <flux:avatar :name="$colleague['name']" color="auto" size="sm" />

                            @if ($colleague['online'])
                                <span class="absolute -end-0.5 -bottom-0.5 size-2.5 rounded-full border-2 border-white bg-green-500 dark:border-zinc-800"></span>
                            @endif
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-zinc-900 dark:text-white">{{ $colleague['name'] }}</span>
                            <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $colleague['position'] }} · {{ $colleague['department'] }}
                            </span>
                        </span>

                        <flux:icon.plus class="size-4 shrink-0 text-zinc-400" />
                    </div>
                @endif
            @empty
                <div class="px-2 py-8 text-center">
                    <flux:icon.user-group class="mx-auto size-6 text-zinc-400" />
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Коллеги не найдены') }}</p>
                </div>
            @endforelse
        </div>

        {{-- Действия --}}
        <div class="flex items-center justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Отмена') }}</flux:button>
            </flux:modal.close>

            <flux:button
                variant="primary"
                wire:click="$set('showNewChatModal', false)"
                :disabled="$mode === 'group' && count($selected) === 0"
                data-test="create-chat-button"
            >
                @if ($mode === 'group')
                    {{ count($selected) > 0
                        ? __('Создать группу (:count)', ['count' => count($selected)])
                        : __('Создать группу') }}
                @else
                    {{ __('Написать сообщение') }}
                @endif
            </flux:button>
        </div>
    </div>
</flux:modal>
