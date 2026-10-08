<?php

use App\Events\ChatRead;
use App\Events\MessageCreated;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

test('sending broadcasts a new message only to chat participants', function () {
    Event::fake([MessageCreated::class]);
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $outsider = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach([$sender->id, $recipient->id]);
    $this->actingAs($sender);

    Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('sendMessage', 'Новое сообщение');

    Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($chat, $sender, $recipient, $outsider): bool {
        expect($event->chatId)->toBe($chat->id)
            ->and($event->broadcastWith()['messageId'])->toBe($chat->messages()->firstOrFail()->id)
            ->and(array_map(fn ($channel): string => $channel->name, $event->broadcastOn()))
            ->toEqualCanonicalizing(['private-users.'.$sender->id, 'private-users.'.$recipient->id])
            ->not->toContain('private-users.'.$outsider->id);

        return true;
    });
});

test('a recipient sees a new message when its broadcast arrives', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $chat = Chat::create(['type' => 'direct']);
    $chat->users()->attach([$sender->id, $recipient->id]);
    $this->actingAs($recipient);
    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id)->assertDontSee('Сообщение без перезагрузки');
    Event::fake([ChatRead::class]);
    $message = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Сообщение без перезагрузки']);

    $component->dispatch('echo-private:users.'.$recipient->id.',.chat.message.created', ['chatId' => $chat->id, 'messageId' => $message->id])
        ->assertSee('Сообщение без перезагрузки')
        ->assertNotDispatched('message-sent')
        ->assertDispatched('incoming-chat-message', chatId: $chat->id, messageId: $message->id, author: $sender->name, chat: $sender->name, body: 'Сообщение без перезагрузки');

    Event::assertNotDispatched(ChatRead::class);

    $component->call('markOpenChatAsRead', $message->id);

    $this->assertDatabaseHas('chat_users', [
        'chat_id' => $chat->id,
        'user_id' => $recipient->id,
        'last_read_message_id' => $message->id,
    ]);
    Event::assertDispatchedOnce(ChatRead::class);
});

test('a broadcast refreshes the chat list when another chat receives a message', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $openChat = Chat::create(['type' => 'group', 'name' => 'Открытая группа']);
    $otherChat = Chat::create(['type' => 'group', 'name' => 'Другая группа']);
    $openChat->users()->attach($recipient->id);
    $otherChat->users()->attach([$sender->id, $recipient->id]);
    $this->actingAs($recipient);
    $component = Livewire::test('pages::chat')->call('selectChat', $openChat->id);
    $message = $otherChat->messages()->create(['user_id' => $sender->id, 'body' => 'Новость в другой группе']);

    $component->dispatch('echo-private:users.'.$recipient->id.',.chat.message.created', ['chatId' => $otherChat->id, 'messageId' => $message->id])
        ->assertSet('activeChatId', $openChat->id)
        ->assertSee('Новость в другой группе')
        ->assertNotDispatched('message-sent')
        ->assertDispatched('incoming-chat-message', chatId: $otherChat->id, messageId: $message->id, author: $sender->name, chat: 'Другая группа', body: 'Новость в другой группе');
});

test('a sender does not receive an incoming message notification for their own message', function () {
    $sender = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach($sender->id);
    $this->actingAs($sender);
    $component = Livewire::test('pages::chat');
    $message = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Моё сообщение']);

    $component->dispatch('echo-private:users.'.$sender->id.',.chat.message.created', ['chatId' => $chat->id, 'messageId' => $message->id])
        ->assertSee('Моё сообщение')
        ->assertNotDispatched('incoming-chat-message');
});

test('a broadcast for a message outside the recipient chat does not create a notification', function () {
    $recipient = User::factory()->create();
    $sender = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach($recipient->id);
    $otherChat = Chat::create(['type' => 'group', 'name' => 'Чужой чат']);
    $otherChat->users()->attach($sender->id);
    $message = $otherChat->messages()->create(['user_id' => $sender->id, 'body' => 'Секретное сообщение']);
    $this->actingAs($recipient);

    Livewire::test('pages::chat')
        ->dispatch('echo-private:users.'.$recipient->id.',.chat.message.created', ['chatId' => $chat->id, 'messageId' => $message->id])
        ->assertDontSee('Секретное сообщение')
        ->assertNotDispatched('incoming-chat-message');
});

test('broadcast channel access is limited to the account owner', function () {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'test-key');
    config()->set('broadcasting.connections.reverb.secret', 'test-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'test-app');
    require base_path('routes/channels.php');
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $channelRequest = ['channel_name' => 'private-users.'.$user->id, 'socket_id' => '123.456'];

    $this->postJson('/broadcasting/auth', $channelRequest)->assertForbidden();

    $this->actingAs($otherUser)
        ->postJson('/broadcasting/auth', $channelRequest)
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', $channelRequest)
        ->assertOk();
});
