<?php

use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('chat.index'))->assertRedirect(route('login'));
});

test('authenticated users can open the chat page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('chat.index'))
        ->assertOk()
        ->assertSee('Отдел разработки')
        ->assertSee('Написать сообщение');
});

test('the chat list can be filtered and searched', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::chat');

    expect($component->instance()->chats)->toHaveCount(8);

    $component->call('setFilter', 'group');

    expect($component->instance()->chats)->toHaveCount(4);

    $component->set('search', 'Атлас');

    expect($component->instance()->chats)
        ->toHaveCount(1)
        ->and($component->instance()->chats[0]['title'])->toBe('Проект «Атлас»');
});

test('selecting a chat opens its conversation and clears its unread counter', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::chat')
        ->assertSet('activeChatId', 10)
        ->call('selectChat', 3)
        ->assertSet('activeChatId', 3)
        ->assertSee('Привет! Напомни, пожалуйста, даты отпуска');

    expect(collect($component->instance()->chats)->firstWhere('id', 3)['unread'])->toBe(0);
});

test('all chats can be marked as read', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::chat');

    expect($component->instance()->unreadTotal)->toBe(11);

    $component->call('markAllAsRead');

    expect($component->instance()->unreadTotal)->toBe(0);
});

test('the chat details panel can be toggled', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::chat')
        ->assertSet('showDetails', false)
        ->call('toggleDetails')
        ->assertSet('showDetails', true)
        ->assertSee('Общие файлы')
        ->call('toggleDetails')
        ->assertSet('showDetails', false);
});

test('a group chat can be composed from the new chat modal', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::chat')
        ->assertSet('newChatMode', 'direct')
        ->call('setNewChatMode', 'group')
        ->assertSet('newChatMode', 'group')
        ->set('selectedColleagues', ['Анна Ковалёва', 'Дмитрий Соколов'])
        ->assertSee('Создать группу (2)')
        ->call('setNewChatMode', 'direct')
        ->assertSet('selectedColleagues', []);
});
