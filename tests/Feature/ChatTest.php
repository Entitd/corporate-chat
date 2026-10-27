<?php

use App\Models\Chat;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('chat.index'))->assertRedirect(route('login'));
});

test('authenticated users can open the chat page', function () {
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Отдел разработки']);
    $chat->users()->attach($user->id);
    $this->actingAs($user);

    $this->get(route('chat.index'))
        ->assertOk()
        ->assertSee('Отдел разработки');
});

test('the chat list can be filtered and searched', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    foreach ([['type' => 'group', 'name' => 'Проект «Атлас»'], ['type' => 'group', 'name' => 'Разработка'], ['type' => 'direct']] as $attributes) {
        $chat = Chat::create($attributes);
        $chat->users()->attach([$user->id, $colleague->id]);
    }
    $this->actingAs($user);

    $component = Livewire::test('pages::chat');
    expect($component->instance()->chats)->toHaveCount(3);

    $component->call('setFilter', 'group');
    expect($component->instance()->chats)->toHaveCount(2);

    $component->set('search', 'Атлас');
    expect($component->instance()->chats)
        ->toHaveCount(1)
        ->and($component->instance()->chats[0]['name'])->toBe('Проект «Атлас»');
});

test('selecting a chat opens its conversation and keeps messages unread until reached', function () {
    $user = User::factory()->create();
    $sender = User::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$user->id, $sender->id]);
    $message = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Привет! Напомни, пожалуйста, даты отпуска']);
    $this->actingAs($user);

    $component = Livewire::test('pages::chat')
        ->assertSet('activeChatId', 0)
        ->call('selectChat', $chat->id)
        ->assertSet('activeChatId', $chat->id)
        ->assertSee($message->body);

    expect(collect($component->instance()->chats)->firstWhere('id', $chat->id)['unread'])->toBe(1);
    $component->call('markOpenChatAsRead', $message->id);
    expect(collect($component->instance()->chats)->firstWhere('id', $chat->id)['unread'])->toBe(0);
});

test('all chats can be marked as read', function () {
    $user = User::factory()->create();
    $sender = User::factory()->create();
    foreach (range(1, 2) as $number) {
        $chat = Chat::create(['type' => 'group', 'name' => "Группа {$number}"]);
        $chat->users()->attach([$user->id, $sender->id]);
        $chat->messages()->create(['user_id' => $sender->id, 'body' => "Сообщение {$number}"]);
    }
    $this->actingAs($user);

    $component = Livewire::test('pages::chat');
    expect($component->instance()->unreadTotal)->toBe(2);
    $component->call('markAllAsRead');
    expect($component->instance()->unreadTotal)->toBe(0);
    foreach (Chat::all() as $chat) {
        $this->assertDatabaseHas('chat_users', [
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'last_read_message_id' => $chat->messages()->max('id'),
        ]);
    }
});

test('the chat details panel can be toggled for an accessible conversation', function () {
    $user = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Разработка']);
    $chat->users()->attach($user->id);
    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->assertSet('showDetails', false)
        ->call('toggleDetails')
        ->assertSet('showDetails', true)
        ->assertSee('Общие файлы')
        ->call('toggleDetails')
        ->assertSet('showDetails', false);
});

test('a group chat can be composed from the new chat modal', function () {
    $user = User::factory()->create();
    $colleagues = User::factory()->count(2)->create();
    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->assertSet('newChatMode', 'direct')
        ->call('setNewChatMode', 'group')
        ->assertSet('newChatMode', 'group')
        ->set('selectedColleagues', $colleagues->modelKeys())
        ->assertSee('Создать группу (2)')
        ->call('setNewChatMode', 'direct')
        ->assertSet('selectedColleagues', []);
});
