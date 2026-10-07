<?php

use App\Events\ChatRead;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('an outgoing direct message changes from sent to read when the recipient opens the chat', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$sender->id, $recipient->id]);
    $this->actingAs($sender);

    $senderChat = Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('sendMessage', 'Привет')
        ->assertSee('data-message-status="sent"', false)
        ->assertDontSee('data-message-status="read"', false);

    Event::fake([ChatRead::class]);
    $this->actingAs($recipient);
    Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('selectChat', $chat->id)
        ->assertSee('Привет');

    Event::assertDispatchedOnce(ChatRead::class);
    Event::assertDispatched(ChatRead::class, function (ChatRead $event) use ($chat, $sender): bool {
        return $event->broadcastAs() === 'chat.read'
            && $event->broadcastWith() === ['chatId' => $chat->id]
            && array_map(fn ($channel): string => $channel->name, $event->broadcastOn()) === ['private-users.'.$sender->id];
    });

    $this->actingAs($sender);
    $senderChat->dispatch('echo-private:users.'.$sender->id.',.chat.read', ['chatId' => $chat->id])
        ->assertSee('data-message-status="read"', false)
        ->assertDontSee('data-message-status="sent"', false);
});

test('an outgoing group message is read only after every other member opens the chat', function () {
    $sender = User::factory()->create();
    $firstRecipient = User::factory()->create();
    $secondRecipient = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach([$sender->id, $firstRecipient->id, $secondRecipient->id]);
    $this->actingAs($sender);
    $senderChat = Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('sendMessage', 'Обновление');

    $this->actingAs($firstRecipient);
    Livewire::test('pages::chat')->call('selectChat', $chat->id)->assertSee('Обновление');

    $this->actingAs($sender);
    $senderChat->dispatch('echo-private:users.'.$sender->id.',.chat.read', ['chatId' => $chat->id])
        ->assertSee('data-message-status="sent"', false);

    $this->actingAs($secondRecipient);
    Livewire::test('pages::chat')->call('selectChat', $chat->id)->assertSee('Обновление');

    $this->actingAs($sender);
    $senderChat->dispatch('echo-private:users.'.$sender->id.',.chat.read', ['chatId' => $chat->id])
        ->assertSee('data-message-status="read"', false);
});

test('an incoming message is marked as read by polling an already open chat', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$sender->id, $recipient->id]);

    $this->actingAs($recipient);
    $recipientChat = Livewire::test('pages::chat')->call('selectChat', $chat->id);

    $this->actingAs($sender);
    $senderChat = Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->call('sendMessage', 'Новое сообщение')
        ->assertSee('data-message-status="sent"', false);
    $message = $chat->messages()->firstOrFail();

    Event::fake([ChatRead::class]);
    $this->actingAs($recipient);
    $recipientChat->call('refreshOpenChat')->assertSee('Новое сообщение');

    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $chat->id,
        'user_id' => $recipient->id,
        'last_read_message_id' => $message->id,
    ]);
    Event::assertDispatchedOnce(ChatRead::class);

    $this->actingAs($sender);
    $senderChat->call('refreshOpenChat')->assertSee('data-message-status="read"', false);
});
