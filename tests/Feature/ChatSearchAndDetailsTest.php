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

    Livewire::test('pages::chat')
        ->call('toggleMessageSearch')
        ->assertSet('showMessageSearch', true)
        ->set('messageSearch', 'ОТЧЁТ')
        ->assertSee('Нужен отчёт за квартал')
        ->assertDontSee('Обсудим встречу')
        ->assertDontSee('Удалённый отчёт')
        ->assertDontSee('Отчёт в другом чате')
        ->call('toggleMessageSearch')
        ->assertSet('messageSearch', '')
        ->assertSee('Обсудим встречу');
});

test('about chat displays its real participants', function () {
    $user = User::factory()->create(['name' => 'Алиса']);
    $colleague = User::factory()->create(['name' => 'Борис', 'title' => 'Разработчик']);
    $outsider = User::factory()->create(['name' => 'Чужой']);
    createChatFor($user, $colleague);

    $this->actingAs($user);

    Livewire::test('pages::chat')
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

    expect(Livewire::test('pages::chat')->instance()->participants)
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

    Livewire::test('pages::chat')
        ->call('toggleMessageSearch')
        ->set('messageSearch', 'отчёт')
        ->assertSee('Найдено: 31')
        ->assertDontSee('Отчёт 31')
        ->call('changeMessagePage', 2)
        ->assertSet('messagePage', 2)
        ->assertSee('Отчёт 31')
        ->assertDontSee('Отчёт 1');
});

test('about a direct chat shows the colleague name and both members', function () {
    $user = User::factory()->create(['name' => 'Мария']);
    $colleague = User::factory()->create(['name' => 'Павел']);
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$user->id, $colleague->id]);

    $this->actingAs($user);

    Livewire::test('pages::chat')
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
