@props(['colleagues' => [], 'selectedInviteeId' => null, 'show' => false])

<flux:modal wire:model="showInviteModal" class="md:w-[26rem]" data-test="invite-colleague-modal">
    @if ($show)
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Пригласить коллегу') }}</flux:heading>
                <flux:subheading>{{ __('Выберите коллегу, которого хотите добавить в группу') }}</flux:subheading>
            </div>

            <flux:input
                wire:model.live.debounce.300ms="inviteSearch"
                icon="magnifying-glass"
                :placeholder="__('Имя или должность')"
                :aria-label="__('Поиск коллеги')"
                data-test="invite-colleague-search"
            />

            <div class="max-h-72 space-y-1 overflow-y-auto">
                @forelse ($colleagues as $colleague)
                    <button
                        type="button"
                        wire:key="invite-colleague-{{ $colleague['id'] }}"
                        wire:click="$set('selectedInviteeId', {{ $colleague['id'] }})"
                        @class([
                            'flex w-full items-center gap-3 rounded-lg px-2 py-2 text-start hover:bg-zinc-100 dark:hover:bg-zinc-700',
                            'bg-sky-50 dark:bg-sky-900/30' => $selectedInviteeId === $colleague['id'],
                        ])
                    >
                        <flux:avatar :name="$colleague['name']" color="auto" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ $colleague['name'] }}</span>
                            @if ($colleague['title'])
                                <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $colleague['title'] }}</span>
                            @endif
                        </span>
                        @if ($selectedInviteeId === $colleague['id'])
                            <flux:icon.check class="size-4 shrink-0 text-sky-600 dark:text-sky-400" />
                        @endif
                    </button>
                @empty
                    <p class="px-2 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('Коллеги не найдены') }}</p>
                @endforelse
            </div>

            @error('selectedInviteeId')
                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Отмена') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="inviteColleague" :disabled="$selectedInviteeId === null" data-test="confirm-invite-button">
                    {{ __('Пригласить') }}
                </flux:button>
            </div>
        </div>
    @endif
</flux:modal>
