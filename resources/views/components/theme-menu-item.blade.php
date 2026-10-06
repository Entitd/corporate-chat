<flux:menu.item
    as="button"
    type="button"
    icon="swatch"
    class="cursor-pointer"
    x-data
    x-on:click="$flux.appearance = $flux.dark ? 'light' : 'dark'"
    x-on:lofi-close-popovers.stop
    data-test="theme-toggle-button"
>
    <span x-text="$flux.dark ? @js(__('Светлая тема')) : @js(__('Тёмная тема'))">{{ __('Сменить тему') }}</span>
</flux:menu.item>
