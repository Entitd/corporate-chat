<div x-data="chatPushNotifications()" class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700" data-test="push-notifications">
    <flux:button size="sm" class="w-full" x-on:click="toggle()" x-bind:disabled="busy">
        <span x-text="busy ? 'Подождите…' : (enabled ? 'Отключить push на устройстве' : 'Включить уведомления')"></span>
    </flux:button>
    <p x-show="needsInstallation" x-cloak class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
        {{ __('На iPhone добавьте чат на экран «Домой» через меню «Поделиться» и откройте через его иконку.') }}
    </p>
    <p x-show="message" x-cloak x-text="message" role="status" class="mt-2 text-xs text-zinc-600 dark:text-zinc-300"></p>
</div>
