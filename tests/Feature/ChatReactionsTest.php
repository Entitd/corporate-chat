<?php

use App\Events\MessageReactionUpdated;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

function reactionChat(): array
{
    $author = User::factory()->create();
    $reader = User::factory()->create();
    $chat = Chat::create(['type' => 'group', 'name' => 'Реакции']);
    $chat->users()->attach([$author->id, $reader->id]);
    $message = $chat->messages()->create(['user_id' => $author->id, 'body' => 'Привет']);

    return [$author, $reader, $chat, $message];
}

test('participants can add replace and remove their reaction', function () {
    Event::fake([MessageReactionUpdated::class]);
    [$author, $reader, $chat, $message] = reactionChat();
    $this->actingAs($reader);
    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id)
        ->assertSee('pick-message-reaction')->assertDontSee('add-message-reaction')
        ->call('toggleReaction', $message->id, '👍')->assertSee('message-reaction');
    $this->assertDatabaseHas('message_reactions', ['message_id' => $message->id, 'user_id' => $reader->id, 'emoji' => '👍']);
    $component->call('toggleReaction', $message->id, '❤️');
    $this->assertDatabaseCount('message_reactions', 1);
    $this->assertDatabaseHas('message_reactions', ['emoji' => '❤️']);
    $component->call('toggleReaction', $message->id, '❤️');
    $this->assertDatabaseCount('message_reactions', 0);
    Event::assertDispatched(MessageReactionUpdated::class, fn ($event) => $event->chatId === $chat->id
        && $event->messageId === $message->id && count($event->broadcastOn()) === 2);
});

test('reactions are counted and identify the current users reaction', function () {
    [$author, $reader, $chat, $message] = reactionChat();
    $message->reactors()->attach([$author->id => ['emoji' => '🔥'], $reader->id => ['emoji' => '🔥']]);
    $this->actingAs($reader);
    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id);
    $reaction = $component->instance()->messages()['data'][0]['reactions'][0];
    expect($reaction['emoji'])->toBe('🔥')->and($reaction['count'])->toBe(2)->and($reaction['mine'])->toBeTrue();
    $component->call('toggleReaction', $message->id, '🔥');
    $this->assertDatabaseHas('message_reactions', ['user_id' => $author->id, 'emoji' => '🔥']);
    $this->assertDatabaseMissing('message_reactions', ['user_id' => $reader->id]);
});

test('invalid emojis and messages outside the active chat cannot be reacted to', function () {
    [$author, $reader, $chat, $message] = reactionChat();
    $this->actingAs($reader);
    Livewire::test('pages::chat')->call('selectChat', $chat->id)
        ->call('toggleReaction', $message->id, '<script>')->assertStatus(422);
    $other = Chat::create(['type' => 'group', 'name' => 'Другой']);
    $foreign = $other->messages()->create(['user_id' => $author->id, 'body' => 'Чужое']);
    Livewire::test('pages::chat')->call('selectChat', $chat->id)
        ->call('toggleReaction', $foreign->id, '👍')->assertNotFound();
    $message->delete();
    Livewire::test('pages::chat')->call('selectChat', $chat->id)
        ->call('toggleReaction', $message->id, '👍')->assertNotFound();
    $this->assertDatabaseCount('message_reactions', 0);
});

test('former members cannot react even when their chat remains selected', function () {
    [$author, $reader, $chat, $message] = reactionChat();
    $this->actingAs($reader);
    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id);
    $chat->users()->detach($reader);
    $component->call('toggleReaction', $message->id, '👍')->assertForbidden();
    $this->assertDatabaseCount('message_reactions', 0);
});

test('broadcast refresh shows another participants reaction without creating a message', function () {
    [$author, $reader, $chat, $message] = reactionChat();
    $this->actingAs($reader);
    $component = Livewire::test('pages::chat')->call('selectChat', $chat->id);
    $message->reactors()->attach($author->id, ['emoji' => '🎉']);
    $component->call('refreshReadStatus', ['chatId' => $chat->id, 'messageId' => $message->id]);
    $reaction = $component->instance()->messages()['data'][0]['reactions'][0];
    expect($reaction['emoji'])->toBe('🎉')->and($reaction['mine'])->toBeFalse();
    $this->assertDatabaseCount('messages', 1);
});
