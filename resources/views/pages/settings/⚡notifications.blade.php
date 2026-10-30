<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Настройки уведомлений')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Уведомления')" :subheading="__('Управляйте уведомлениями на этом устройстве. Настройки других устройств не изменятся.')">
        <x-chat.push-notifications :settings="true" />
    </x-pages::settings.layout>
</section>
