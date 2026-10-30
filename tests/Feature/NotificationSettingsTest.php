<?php

use App\Models\User;

test('notification controls are available in settings for signed in users', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('notifications.edit'))
        ->assertOk()
        ->assertSee('Отключить уведомления на этом устройстве')
        ->assertSee('Настройки других устройств не изменятся.');
});

test('guests cannot access notification settings', function () {
    $this->get(route('notifications.edit'))->assertRedirect(route('login'));
});


test('chat shows a notification status and settings link instead of a disable control', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('chat.index'))
        ->assertOk()
        ->assertSee('push-enabled-status')
        ->assertSee(route('notifications.edit'))
        ->assertDontSee('Отключить уведомления на этом устройстве');
});
