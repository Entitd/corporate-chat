<?php

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

    Livewire::test('pages::chat')->call('sendMessage', 'Новое сообщение');

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
    $component = Livewire::test('pages::chat')->assertDontSee('Сообщение без перезагрузки');
    $message = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'Сообщение без перезагрузки']);

    $component->dispatch('echo-private:users.'.$recipient->id.',.chat.message.created', ['chatId' => $chat->id, 'messageId' => $message->id])
        ->assertSee('Сообщение без перезагрузки')
        ->assertDispatched('message-sent');
});

test('a broadcast refreshes the chat list when another chat receives a message', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $openChat = Chat::create(['type' => 'group', 'name' => 'Открытая группа']);
    $otherChat = Chat::create(['type' => 'group', 'name' => 'Другая группа']);
    $openChat->users()->attach($recipient->id);
    $otherChat->users()->attach([$sender->id, $recipient->id]);
    $this->actingAs($recipient);
    $component = Livewire::test('pages::chat')->assertSet('activeChatId', $openChat->id);
    $message = $otherChat->messages()->create(['user_id' => $sender->id, 'body' => 'Новость в другой группе']);

    $component->dispatch('echo-private:users.'.$recipient->id.',.chat.message.created', ['chatId' => $otherChat->id, 'messageId' => $message->id])
        ->assertSet('activeChatId', $openChat->id)
        ->assertSee('Новость в другой группе')
        ->assertNotDispatched('message-sent');
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
