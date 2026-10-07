<?php

use App\Models\Chat;
use App\Models\User;
use Livewire\Livewire;

test('the sender can see which group members have read their message', function () {
    $sender = User::factory()->create(['name' => 'Алиса']);
    $reader = User::factory()->create(['name' => 'Борис']);
    $unreadMember = User::factory()->create(['name' => 'Вера']);
    $chat = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $chat->users()->attach([$sender->id, $reader->id, $unreadMember->id]);
    $message = $chat->messages()->create(['user_id' => $sender->id, 'body' => 'План готов']);
    $this->actingAs($reader);
    Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('markOpenChatAsRead', $message->id)->assertSee('План готов');

    $this->actingAs($sender);
    $component = Livewire::test('pages::chat')
        ->call('selectChat', $chat->id)
        ->assertSee('data-test="show-message-readers"', false)
        ->call('showMessageReaders', $message->id)
        ->assertSet('showMessageReadersModal', true)
        ->assertSee('data-test="message-readers-modal"', false)
        ->assertSee('Прочитали · 1')
        ->assertSee('Ещё не прочитали · 1');

    expect($component->instance()->messageReaders)->toBe([
        'read' => [['id' => $reader->id, 'name' => 'Борис']],
        'unread' => [['id' => $unreadMember->id, 'name' => 'Вера']],
    ]);

    $this->actingAs($unreadMember);
    Livewire::test('pages::chat')->call('selectChat', $chat->id)->call('markOpenChatAsRead', $message->id)->assertSee('План готов');

    $this->actingAs($sender);
    $component->call('$refresh')->assertSee('Прочитали · 2');
    expect($component->instance()->messageReaders['unread'])->toBe([]);
});

test('message readers are limited to the sender of a message in the active group', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();
    $group = Chat::create(['type' => 'group', 'name' => 'Команда']);
    $group->users()->attach([$sender->id, $recipient->id]);
    $message = $group->messages()->create(['user_id' => $sender->id, 'body' => 'Секрет']);
    $this->actingAs($recipient);

    Livewire::test('pages::chat')->call('selectChat', $group->id)->call('showMessageReaders', $message->id)->assertForbidden();

    $direct = Chat::create(['type' => 'direct']);
    $direct->users()->attach([$sender->id, $recipient->id]);
    $directMessage = $direct->messages()->create(['user_id' => $sender->id, 'body' => 'Личное']);
    $this->actingAs($sender);

    Livewire::test('pages::chat')->call('selectChat', $group->id)->call('showMessageReaders', $directMessage->id)->assertNotFound();
    Livewire::test('pages::chat')
        ->call('selectChat', $direct->id)
        ->call('showMessageReaders', $directMessage->id)
        ->assertForbidden();
});
