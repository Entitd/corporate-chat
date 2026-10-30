@props(['inMenu' => false])

<div wire:ignore x-data="chatPushNotifications()" x-cloak x-show="{{ $inMenu ? 'enabled || (!checked && message)' : 'checked && !enabled && !busy' }}" x-on:device-push-changed.window="enabled = $event.detail.enabled; checked = true; message = ''" @class(['rounded-xl bg-sky-50 px-3 py-2.5 text-sky-800 dark:bg-sky-950/40 dark:text-sky-200', 'my-2' => $inMenu, 'mx-4 mb-1' => ! $inMenu]) data-test="push-notifications" data-location="{{ $inMenu ? 'menu' : 'banner' }}">
    <button type="button" class="flex min-h-8 w-full items-center gap-2 text-start text-xs font-medium disabled:opacity-50" x-on:click="{{ $inMenu ? 'if (checked) toggle(); else init()' : 'if (!enabled) toggle()' }}" x-bind:disabled="busy">
        <flux:icon.bell class="size-4 shrink-0" />
        <span class="flex-1" x-text="busy ? 'Подождите…' : (!checked ? 'Проверить уведомления' : (enabled ? 'Отключить уведомления на этом устройстве' : 'Включить уведомления'))"></span>
    </button>
    <p x-show="needsInstallation" x-cloak class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
        {{ __('На iPhone добавьте чат на экран «Домой» через меню «Поделиться» и откройте через его иконку.') }}
    </p>
    <p x-show="message" x-cloak x-text="message" role="status" class="mt-2 text-xs text-zinc-600 dark:text-zinc-300"></p>
</div>
