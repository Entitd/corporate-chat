<?php

use App\Models\Chat;
use App\Models\User;
use Livewire\Livewire;

function createChatFor(User ...$users): Chat
{
    $chat = Chat::create(['name' => 'Команда проекта', 'type' => 'group']);
    $chat->users()->attach(array_map(fn (User $user): int => $user->id, $users));

    return $chat;
}

test('participant can search messages only in the open chat and close the search', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    $chat = createChatFor($user, $colleague);
    $otherChat = createChatFor($user);
    $chat->messages()->create(['user_id' => $colleague->id, 'body' => 'Нужен отчёт за квартал']);
    $chat->messages()->create(['user_id' => $colleague->id, 'body' => 'Обсудим встречу']);
    $chat->messages()->create(['user_id' => $colleague->id, 'body' => 'Удалённый отчёт'])->delete();
    $otherChat->messages()->create(['user_id' => $user->id, 'body' => 'Отчёт в другом чате']);

    $this->actingAs($user);

    $component = Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('toggleMessageSearch')
        ->assertSet('showMessageSearch', true)
        ->set('messageSearch', 'ОТЧЁТ');

    expect(array_column($component->instance()->messages['data'], 'body'))
        ->toBe(['Нужен отчёт за квартал']);

    $component->call('toggleMessageSearch')
        ->assertSet('messageSearch', '')
        ->assertSee('Обсудим встречу');
});

test('about chat displays its real participants', function () {
    $user = User::factory()->create(['name' => 'Алиса']);
    $colleague = User::factory()->create(['name' => 'Борис', 'title' => 'Разработчик']);
    $outsider = User::factory()->create(['name' => 'Чужой']);
    $chat = createChatFor($user, $colleague);

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('toggleDetails')
        ->assertSet('showDetails', true)
        ->assertSee('Команда проекта')
        ->assertSee('Алиса')
        ->assertSee('Борис')
        ->assertSee('Разработчик')
        ->assertSee('data-test="chat-participants"', false)
        ->assertSee('data-test="invite-colleague-button"', false)
        ->assertSee('data-test="invite-colleague-menu-item"', false)
        ->call('toggleDetails')
        ->assertSet('showDetails', false)
        ->call('showParticipants')
        ->assertSet('showDetails', true);

    expect(Livewire::test('pages::chat')->call('selectChat', $chat->id)->instance()->participants)
        ->toHaveCount(2)
        ->not->toContain(['name' => 'Чужой']);
});

test('search results can be viewed beyond the first page', function () {
    $user = User::factory()->create();
    $chat = createChatFor($user);

    foreach (range(1, 31) as $number) {
        $chat->messages()->create(['user_id' => $user->id, 'body' => "Отчёт {$number}"]);
    }

    $this->actingAs($user);

    $component = Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('toggleMessageSearch')
        ->set('messageSearch', 'отчёт')
        ->assertSee('Найдено: 31');

    expect(array_column($component->instance()->messages['data'], 'body'))
        ->not->toContain('Отчёт 31');

    $component->call('changeMessagePage', 2)->assertSet('messagePage', 2);

    expect(array_column($component->instance()->messages['data'], 'body'))
        ->toContain('Отчёт 31')
        ->not->toContain('Отчёт 1');
});

test('about a direct chat shows the colleague name and both members', function () {
    $user = User::factory()->create(['name' => 'Мария']);
    $colleague = User::factory()->create(['name' => 'Павел']);
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$user->id, $colleague->id]);

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('toggleDetails')
        ->assertSee('Павел')
        ->assertSee('Мария')
        ->assertDontSee('Пригласить коллегу')
        ->assertDontSee('data-test="invite-colleague-button"', false)
        ->assertDontSee('data-test="invite-colleague-menu-item"', false);
});

test('another chat cannot be selected or searched by changing the public id', function () {
    $user = User::factory()->create();
    $outsider = User::factory()->create();
    createChatFor($user);
    $privateChat = createChatFor($outsider);
    $privateChat->messages()->create(['user_id' => $outsider->id, 'body' => 'Секретное сообщение']);

    $this->actingAs($user);

    Livewire::test('pages::chat')->call('selectChat', $privateChat->id)->assertStatus(403);
    Livewire::test('pages::chat')->set('activeChatId', $privateChat->id)->assertStatus(403);
});

test('chat page handles an empty chat list', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('chat.index'))->assertOk()->assertSee('Выберите чат');
});

test('opening the chat page without a chat parameter leaves conversations unselected and unread', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    $chat = createChatFor($user, $colleague);
    $chat->messages()->create(['user_id' => $colleague->id, 'body' => 'Непрочитанное сообщение']);
    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->assertSet('activeChatId', 0)
        ->assertSee('Выберите чат')
        ->assertDontSee('data-test="chat-thread"', false);

    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $chat->id,
        'user_id' => $user->id,
        'last_read_message_id' => null,
    ]);
});

test('opening a chat from its link selects it and marks its messages as read', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    $chat = createChatFor($user, $colleague);
    $message = $chat->messages()->create(['user_id' => $colleague->id, 'body' => 'Сообщение по ссылке']);
    $this->actingAs($user);

    Livewire::withQueryParams(['chat' => $chat->id])
        ->test('pages::chat')
        ->assertSet('activeChatId', $chat->id)
        ->assertSee('Сообщение по ссылке');

    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $chat->id,
        'user_id' => $user->id,
        'last_read_message_id' => $message->id,
    ]);
});

test('chat list shows the latest visible message and supports mobile navigation', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create(['name' => 'Анна']);
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$user->id, $colleague->id]);
    $chat->messages()->create(['user_id' => $colleague->id, 'body' => 'Первое сообщение']);
    $chat->messages()->create(['user_id' => $user->id, 'body' => 'Последний ответ']);
    $chat->messages()->create(['user_id' => $colleague->id, 'body' => 'Удалённое сообщение'])->delete();
    $emptyChat = createChatFor($user);

    $this->actingAs($user);

    $component = Livewire::test('pages::chat')
        ->assertSee('Выберите чат')
        ->assertDontSee('Удалённое сообщение')
        ->assertSet('showChatList', true);

    expect(array_column($component->instance()->chats, 'id'))->toBe([$chat->id, $emptyChat->id]);

    $component
        ->call('selectChat', $chat->id)
        ->assertSee('Последний ответ')
        ->assertSet('showChatList', false)
        ->call('backToList')
        ->assertSet('showChatList', true);
});
