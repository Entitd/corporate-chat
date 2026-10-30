<div wire:ignore x-data="chatPushNotifications()" class="mx-4 mb-1 rounded-xl bg-sky-50 px-3 py-2.5 text-sky-800 dark:bg-sky-950/40 dark:text-sky-200" data-test="push-notifications">
    <button type="button" class="flex min-h-8 w-full items-center gap-2 text-start text-xs font-medium disabled:opacity-50" x-on:click="toggle()" x-bind:disabled="busy">
        <flux:icon.bell class="size-4 shrink-0" />
        <span class="flex-1" x-text="busy ? 'Подождите…' : (enabled ? 'Уведомления включены' : 'Включить уведомления')"></span>
        <flux:icon.chevron-right class="size-3.5 shrink-0 opacity-50" />
    </button>
    <p x-show="needsInstallation" x-cloak class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
        {{ __('На iPhone добавьте чат на экран «Домой» через меню «Поделиться» и откройте через его иконку.') }}
    </p>
    <p x-show="message" x-cloak x-text="message" role="status" class="mt-2 text-xs text-zinc-600 dark:text-zinc-300"></p>
</div>
