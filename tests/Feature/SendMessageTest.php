<?php

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use Livewire\Livewire;

test('a participant can send a message to a chat', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();

    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$user->id, $colleague->id]);

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->set('activeChatId', $chat->id)
        ->set('messageBody', 'Привет, коллега!')
        ->call('sendMessage')
        ->assertSet('messageBody', '');

    $this->assertDatabaseHas('messages', [
        'chat_id' => $chat->id,
        'user_id' => $user->id,
        'body' => 'Привет, коллега!',
    ]);
});

test('sending uses the current editor text and immediately shows the newest message', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$user->id, $colleague->id]);

    foreach (range(1, 31) as $number) {
        $chat->messages()->create(['user_id' => $colleague->id, 'body' => sprintf('Старое сообщение %03d', $number)]);
    }

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->assertSee('Старое сообщение 031')
        ->assertDontSee('Старое сообщение 001')
        ->set('messageBody', 'Текст с задержкой')
        ->call('sendMessage', "Актуальный текст\nВторая строка")
        ->assertSet('messageBody', '')
        ->assertSee("Актуальный текст\nВторая строка")
        ->assertDontSee('Старое сообщение 002')
        ->assertDispatched('message-sent')
        ->assertSee('Загрузить предыдущие сообщения')
        ->call('loadOlderMessages')
        ->assertSee('Старое сообщение 001');

    $this->assertDatabaseHas('messages', [
        'chat_id' => $chat->id,
        'user_id' => $user->id,
        'body' => "Актуальный текст\nВторая строка",
    ]);
    $this->assertDatabaseMissing('messages', ['body' => 'Текст с задержкой']);
});

test('an empty message is not sent', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();

    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$user->id, $colleague->id]);

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->set('activeChatId', $chat->id)
        ->set('messageBody', "   \n  ")
        ->call('sendMessage')
        ->assertSet('messageBody', '');

    $this->assertDatabaseMissing('messages', ['chat_id' => $chat->id]);
});

test('a message longer than the limit is rejected', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();

    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$user->id, $colleague->id]);

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->set('activeChatId', $chat->id)
        ->set('messageBody', str_repeat('а', 5001))
        ->call('sendMessage')
        ->assertHasErrors(['messageBody' => 'max']);

    $this->assertDatabaseMissing('messages', ['chat_id' => $chat->id]);
});

test('a non-participant cannot send a message', function () {
    $user = User::factory()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();

    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$first->id, $second->id]);

    $this->actingAs($user);

    Livewire::test('pages::chat')
        ->set('messageBody', 'Не должен отправиться')
        ->set('activeChatId', $chat->id)
        ->assertStatus(403);

    expect(Message::where('chat_id', $chat->id)->count())->toBe(0);
});
